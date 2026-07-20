<?php

declare(strict_types=1);

namespace KloudStack\Observability\Admin;

use KloudStack\Observability\Config;
use KloudStack\Observability\Context\AzureContext;
use KloudStack\Observability\Plugin;
use KloudStack\Observability\Settings;
use KloudStack\Observability\Telemetry\Envelope;
use KloudStack\Observability\Telemetry\Privacy;
use KloudStack\Observability\Transport\CircuitBreaker;
use KloudStack\Observability\Transport\ResponseRelease;
use KloudStack\Observability\Transport\Transport;

defined('ABSPATH') || exit;

/**
 * Self-test.
 *
 * The single most valuable support-cost reducer in the plugin. "Telemetry isn't appearing" has
 * roughly a dozen causes — wrong connection string, blocked egress, an open circuit breaker,
 * consent withheld, sampling, full-page caching — and without this, distinguishing them means a
 * support conversation. With it, the administrator pastes one report.
 *
 * Checks report a status and a human-readable explanation. A check that cannot determine an
 * answer reports STATUS_UNKNOWN rather than guessing: a self-test that claims everything is fine
 * when it does not know is worse than no self-test.
 */
final class Diagnostics
{
    public const STATUS_PASS    = 'pass';
    public const STATUS_WARN    = 'warn';
    public const STATUS_FAIL    = 'fail';
    public const STATUS_UNKNOWN = 'unknown';

    /** Set by the App Service Application Insights extension when auto-instrumentation is on. */
    private const APP_SERVICE_AGENT = 'ApplicationInsightsAgent_EXTENSION_VERSION';

    /** @var Config */
    private $config;

    /** @var Settings */
    private $settings;

    /** @var Plugin */
    private $plugin;

    /** @var callable|null */
    private $sender;

    /**
     * @param callable|null $sender Injectable transport for testing.
     */
    public function __construct(Config $config, Settings $settings, Plugin $plugin, ?callable $sender = null)
    {
        $this->config   = $config;
        $this->settings = $settings;
        $this->plugin   = $plugin;
        $this->sender   = $sender;
    }

    /**
     * Run every check.
     *
     * @param bool $includeLive Whether to perform the live round-trip to Azure. Skipped in
     *                          contexts where an outbound call is inappropriate, such as Site
     *                          Health's automatic background tests.
     *
     * @return array<int, array{id: string, label: string, status: string, message: string}>
     */
    public function run(bool $includeLive = true): array
    {
        $checks = [
            $this->checkConfiguration(),
            $this->checkTelemetryEnabled(),
            $this->checkAzureEnvironment(),
            $this->checkLoadMode(),
            $this->checkResponseRelease(),
            $this->checkCircuitBreaker(),
            $this->checkDuplicateInstrumentation(),
            $this->checkClientTelemetry(),
            $this->checkSampling(),
        ];

        if ($includeLive && $this->config->isConfigured()) {
            $checks[] = $this->checkLiveTelemetry();
        }

        return $checks;
    }

    /**
     * Whether anything is actually wrong.
     */
    public function hasFailures(bool $includeLive = true): bool
    {
        foreach ($this->run($includeLive) as $check) {
            if ($check['status'] === self::STATUS_FAIL) {
                return true;
            }
        }

        return false;
    }

    /**
     * Plain-text report, for pasting into a support ticket.
     */
    public function asText(bool $includeLive = true): string
    {
        $lines = [
            'KloudStack Observability for Azure — diagnostics',
            'Plugin ' . \KloudStack\Observability\VERSION
                . ' | schema v' . \KloudStack\Observability\SCHEMA_VERSION
                . ' | PHP ' . PHP_VERSION,
            str_repeat('-', 60),
        ];

        foreach ($this->run($includeLive) as $check) {
            $lines[] = sprintf(
                '[%s] %s: %s',
                strtoupper($check['status']),
                $check['label'],
                $check['message']
            );
        }

        return implode("\n", $lines);
    }

    // ── Individual checks ───────────────────────────────────────────────────────────────────

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkConfiguration(): array
    {
        if (!$this->config->isConfigured()) {
            return self::result(
                'configuration',
                'Connection',
                self::STATUS_FAIL,
                'No Application Insights connection string. Set APPLICATIONINSIGHTS_CONNECTION_STRING '
                . 'in your App Service application settings, or enter one on this page.'
            );
        }

        $sources = [
            Config::SOURCE_CONSTANT => 'a wp-config.php constant',
            Config::SOURCE_ENV      => 'an environment variable',
            Config::SOURCE_ENV_IKEY => 'a legacy instrumentation key environment variable',
            Config::SOURCE_OPTION   => 'this settings page',
        ];

        $source = $sources[$this->config->source()] ?? 'an unknown source';

        if ($this->config->source() === Config::SOURCE_ENV_IKEY) {
            return self::result(
                'configuration',
                'Connection',
                self::STATUS_WARN,
                'Configured from ' . $source . '. Instrumentation keys are deprecated by Azure; '
                . 'switch to a connection string.'
            );
        }

        return self::result('configuration', 'Connection', self::STATUS_PASS, 'Configured from ' . $source . '.');
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkTelemetryEnabled(): array
    {
        if (!$this->settings->bool('enabled')) {
            return self::result(
                'enabled',
                'Telemetry',
                self::STATUS_WARN,
                'Telemetry is switched off in settings, so nothing is being collected.'
            );
        }

        return self::result('enabled', 'Telemetry', self::STATUS_PASS, 'Enabled.');
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkAzureEnvironment(): array
    {
        $azure = new AzureContext($this->settings->string('cloud_role'));

        if (!$azure->isAzure()) {
            return self::result(
                'azure',
                'Azure environment',
                self::STATUS_WARN,
                'Not detected as an Azure environment. Telemetry still works, but Azure context '
                . '(site name, slot, region) will be missing and support is limited to Azure hosting.'
            );
        }

        $message = sprintf(
            'Detected %s, role "%s"',
            $azure->environment(),
            $azure->role()
        );

        if ($azure->region() !== '') {
            $message .= ', region ' . $azure->region();
        }

        if (!$azure->isProductionSlot()) {
            $message .= ', slot ' . $azure->slot();
        }

        return self::result('azure', 'Azure environment', self::STATUS_PASS, $message . '.');
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkLoadMode(): array
    {
        if ($this->plugin->loadMode() === Plugin::MODE_MU) {
            return self::result(
                'load_mode',
                'Load mode',
                self::STATUS_PASS,
                'Loaded as a must-use plugin, so fatal errors raised while other plugins load are captured.'
            );
        }

        return self::result(
            'load_mode',
            'Load mode',
            self::STATUS_PASS,
            'Loaded as a standard plugin. Fatal errors raised before this plugin loads cannot be captured; '
            . 'that requires the must-use loader.'
        );
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkResponseRelease(): array
    {
        $mechanism = ResponseRelease::availableMechanism();

        if (ResponseRelease::isReliable()) {
            return self::result(
                'response_release',
                'Response timing',
                self::STATUS_PASS,
                'Using ' . $mechanism . '(). Telemetry is sent after the response reaches the visitor, '
                . 'so Azure latency cannot affect page load time.'
            );
        }

        if ($mechanism === ResponseRelease::MECHANISM_NONE) {
            return self::result(
                'response_release',
                'Response timing',
                self::STATUS_UNKNOWN,
                'No response to release in this context.'
            );
        }

        return self::result(
            'response_release',
            'Response timing',
            self::STATUS_WARN,
            'fastcgi_finish_request() is unavailable, so the plugin falls back to flushing output buffers. '
            . 'This usually works, but the guarantee that Azure latency never affects page load is weaker. '
            . 'PHP-FPM provides the reliable mechanism.'
        );
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkCircuitBreaker(): array
    {
        $breaker = new CircuitBreaker();

        if (!$breaker->isOpen()) {
            $failures = $breaker->failureCount();

            if ($failures > 0) {
                return self::result(
                    'circuit_breaker',
                    'Transmission',
                    self::STATUS_WARN,
                    sprintf('%d recent transmission failure(s). Last error: %s', $failures, $breaker->lastFailureReason())
                );
            }

            return self::result('circuit_breaker', 'Transmission', self::STATUS_PASS, 'No recent failures.');
        }

        return self::result(
            'circuit_breaker',
            'Transmission',
            self::STATUS_FAIL,
            'Suspended: the ingestion endpoint could not be reached repeatedly. Last error: '
            . ($breaker->lastFailureReason() ?: 'unknown')
            . '. Transmission resumes automatically within five minutes of the endpoint recovering.'
        );
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkDuplicateInstrumentation(): array
    {
        $agent = getenv(self::APP_SERVICE_AGENT);

        if (is_string($agent) && $agent !== '' && $agent !== '~0' && strtolower($agent) !== 'disabled') {
            return self::result(
                'duplicate',
                'Duplicate instrumentation',
                self::STATUS_WARN,
                'The App Service Application Insights extension is also enabled. Requests may be recorded '
                . 'twice and ingestion charged twice. Disable one of the two.'
            );
        }

        return self::result(
            'duplicate',
            'Duplicate instrumentation',
            self::STATUS_PASS,
            'No conflicting auto-instrumentation detected.'
        );
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkClientTelemetry(): array
    {
        if (!$this->settings->bool('client_enabled')) {
            return self::result(
                'client',
                'Browser telemetry',
                self::STATUS_WARN,
                'Disabled, so page views and JavaScript errors are not collected.'
            );
        }

        if (!Privacy::hasConsent()) {
            return self::result(
                'client',
                'Browser telemetry',
                self::STATUS_WARN,
                'Suppressed: a consent filter is currently withholding consent, so the SDK is not injected.'
            );
        }

        $mode = $this->settings->bool('cookieless')
            ? 'cookie-less mode (no session or user aggregation)'
            : 'standard mode (sets ai_user and ai_session cookies)';

        return self::result('client', 'Browser telemetry', self::STATUS_PASS, 'Enabled in ' . $mode . '.');
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkSampling(): array
    {
        $rate = $this->settings->samplingRate();

        if ($rate >= 100) {
            return self::result('sampling', 'Sampling', self::STATUS_PASS, 'All requests recorded.');
        }

        return self::result(
            'sampling',
            'Sampling',
            self::STATUS_PASS,
            sprintf(
                '%d%% of requests recorded. Azure extrapolates totals, so counts remain approximately correct, '
                . 'but an individual request may be absent.',
                $rate
            )
        );
    }

    /**
     * Send a real telemetry item and report what the endpoint said.
     *
     * Deliberately goes through the same Transport and Envelope the plugin uses in production. A
     * self-test with its own parallel implementation can pass while the real path is broken,
     * which is the failure mode this check exists to rule out.
     *
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkLiveTelemetry(): array
    {
        $credentials = $this->config->credentials();

        if ($credentials === null) {
            return self::result('live', 'Test telemetry', self::STATUS_UNKNOWN, 'Not configured.');
        }

        // A dedicated breaker, on its own transient key, so a failing self-test neither suspends
        // production telemetry nor inflates the production failure count — and an already-open
        // production breaker does not stop the test from running and reporting what is wrong.
        $breaker  = new CircuitBreaker(PHP_INT_MAX, 1, CircuitBreaker::TRANSIENT . '_selftest');
        $envelope = new Envelope($credentials['ikey'], 'php:kloudstack_' . \KloudStack\Observability\VERSION);

        $item = $envelope->build('Event', 'EventData', [
            'name'       => 'KloudStack Observability self-test',
            'properties' => [
                'schema_version' => (string) \KloudStack\Observability\SCHEMA_VERSION,
                'plugin_version' => \KloudStack\Observability\VERSION,
                'self_test'      => 'true',
            ],
        ], [
            'ai.operation.name' => 'diagnostics',
        ]);

        $transport = new Transport($credentials['endpoint'], $breaker, $this->sender);
        $started   = microtime(true);
        $accepted  = $transport->send([$item]);
        $elapsedMs = (int) round((microtime(true) - $started) * 1000);

        if ($accepted) {
            return self::result(
                'live',
                'Test telemetry',
                self::STATUS_PASS,
                sprintf(
                    'Accepted by Azure in %dms. It may take two to three minutes to appear in the portal; '
                    . 'search customEvents for "KloudStack Observability self-test".',
                    $elapsedMs
                )
            );
        }

        return self::result(
            'live',
            'Test telemetry',
            self::STATUS_FAIL,
            'Rejected or unreachable: ' . ($breaker->lastFailureReason() ?: 'the endpoint did not accept the item')
            . '. Check outbound HTTPS access to ' . $credentials['endpoint'] . '.'
        );
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private static function result(string $id, string $label, string $status, string $message): array
    {
        return [
            'id'      => $id,
            'label'   => $label,
            'status'  => $status,
            'message' => $message,
        ];
    }
}
