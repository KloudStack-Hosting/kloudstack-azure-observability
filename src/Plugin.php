<?php

declare(strict_types=1);

namespace KloudStack\Observability;

use KloudStack\Observability\Client\SnippetInjector;
use KloudStack\Observability\Collector\ExceptionCollector;
use KloudStack\Observability\Collector\RequestCollector;
use KloudStack\Observability\Context\AzureContext;
use KloudStack\Observability\Context\Correlation;
use KloudStack\Observability\Context\WordPressContext;
use KloudStack\Observability\Support\Guard;
use KloudStack\Observability\Support\Log;
use KloudStack\Observability\Telemetry\Buffer;
use KloudStack\Observability\Telemetry\Envelope;
use KloudStack\Observability\Telemetry\Privacy;
use KloudStack\Observability\Telemetry\Reporter;
use KloudStack\Observability\Telemetry\Sampler;
use KloudStack\Observability\Transport\CircuitBreaker;
use KloudStack\Observability\Transport\ResponseRelease;
use KloudStack\Observability\Transport\Transport;

defined('ABSPATH') || exit;

/**
 * Plugin container and wiring.
 *
 * Boot is ordered so that an unconfigured site costs almost nothing: configuration is resolved
 * first, and if the plugin has no credentials no collector is registered at all.
 */
final class Plugin
{
    public const MODE_MU       = 'mu';
    public const MODE_STANDARD = 'standard';

    /** @var Config */
    private $config;

    /** @var Settings */
    private $settings;

    /** @var Reporter|null */
    private $reporter = null;

    /** @var bool */
    private $booted = false;

    public function __construct(?Config $config = null, ?Settings $settings = null)
    {
        $this->config   = $config ?? new Config();
        $this->settings = $settings ?? new Settings();
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    /**
     * The active reporter, or null when telemetry is not running.
     *
     * Exposed so the diagnostics self-test can send a test item through exactly the same path
     * production telemetry takes, rather than a parallel implementation that could pass while the
     * real one is broken.
     */
    public function reporter(): ?Reporter
    {
        return $this->reporter;
    }

    /**
     * How the plugin was loaded.
     *
     * MU mode loads before regular plugins and therefore captures fatal errors raised while they
     * load; standard mode cannot. This is the only behavioural difference between the two, and
     * it is why KloudStack managed stacks keep the MU-loader. Reported in diagnostics.
     */
    public function loadMode(): string
    {
        return defined('KLOUDSTACK_OBS_DEFER_BOOT') ? self::MODE_MU : self::MODE_STANDARD;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        Guard::run(function (): void {
            // Bridge the saved 'debug_log' option to the filter Log::enabled() reads. This MUST run
            // before anything logs, because Log memoises enabled() on first use — without it the
            // "Debug log" settings toggle writes its option but never actually enables logging.
            add_filter('kloudstack_obs_debug_log', fn (): bool => $this->settings->bool('debug_log'));

            $this->registerAdmin();

            if (!$this->config->isConfigured()) {
                $this->registerUnconfiguredNotice();

                return;
            }

            if (!$this->isTelemetryEnabled()) {
                Log::debug('Telemetry disabled by setting; collectors not registered.');

                return;
            }

            $this->registerCollectors();
        }, 'plugin.boot');
    }

    /**
     * Master kill switch, independent of whether credentials are present.
     */
    private function isTelemetryEnabled(): bool
    {
        return $this->settings->bool('enabled');
    }

    /**
     * Build the telemetry pipeline and register the collectors.
     *
     * Object construction here is cheap and allocation-only — no network, no database, no
     * filesystem. The expensive work happens after the response has been released.
     */
    private function registerCollectors(): void
    {
        $credentials = $this->config->credentials();

        if ($credentials === null) {
            return;
        }

        $azure = new AzureContext(
            $this->settings->string('cloud_role'),
            self::siteHost()
        );

        $wordpress = new WordPressContext(
            SCHEMA_VERSION,
            VERSION,
            $this->loadMode(),
            $this->config->isManaged()
        );

        $correlation = new Correlation();

        $privacy = new Privacy(
            $this->settings->bool('anonymise_ip'),
            $this->settings->queryAllowlist(),
            $this->settings->bool('header_tracking')
        );

        $this->reporter = new Reporter(
            new Envelope($credentials['ikey'], 'php:kloudstack_' . VERSION),
            new Buffer(),
            new Sampler($this->settings->samplingRate()),
            new Transport($credentials['endpoint'], new CircuitBreaker()),
            new ResponseRelease(),
            $azure,
            $wordpress,
            $correlation
        );

        // The exception collector registers first so its shutdown handler runs before the
        // request collector's. That ordering matters: a fatal must be recorded into the buffer
        // before the request collector flushes it, or the fatal is collected and then thrown away
        // with nothing to send it.
        (new ExceptionCollector($this->reporter))->register();
        (new RequestCollector($this->reporter, $this->settings, $privacy))->register();

        // REST routes are the authoritative operation name when a request is a REST call.
        add_filter('rest_pre_dispatch', Guard::wrap(
            static function ($result, $server, $request) {
                if (is_object($request) && method_exists($request, 'get_route')) {
                    $GLOBALS['kloudstack_obs_rest_route'] = (string) $request->get_route();
                }

                return $result;
            },
            'rest.route_capture'
        ), 10, 3);

        if (!is_admin()) {
            (new SnippetInjector($this->config, $this->settings, $correlation, $azure))->register();
        }

        Log::debug('Telemetry collectors registered.', [
            'source'    => $this->config->source(),
            'load_mode' => $this->loadMode(),
            'role'      => $azure->role(),
        ]);
    }

    /**
     * The site's host, used as a cloud role fallback when Azure metadata is unavailable.
     */
    private static function siteHost(): string
    {
        if (!function_exists('home_url')) {
            return '';
        }

        $host = wp_parse_url((string) home_url(), PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    /**
     * Admin surface.
     *
     * Phase E of the implementation plan.
     */
    private function registerAdmin(): void
    {
        if (!is_admin()) {
            return;
        }

        // Registered even when the plugin is unconfigured — that is precisely when an
        // administrator needs the settings page and the self-test.
        (new Admin\SettingsPage($this->config, $this->settings, $this))->register();
        (new Admin\SiteHealth($this->config, $this->settings, $this))->register();
    }

    /**
     * Tell administrators why nothing is being collected.
     *
     * Only shown to users who could actually fix it, and suppressed on managed stacks where the
     * connection string arrives from the environment and an empty value means a provisioning
     * problem rather than a configuration one.
     */
    private function registerUnconfiguredNotice(): void
    {
        if ($this->config->isManaged()) {
            return;
        }

        add_action('admin_notices', Guard::wrap(static function (): void {
            if (!current_user_can('manage_options')) {
                return;
            }

            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html__(
                    'KloudStack Observability for Azure is active but has no Application Insights connection string, so no telemetry is being collected.',
                    'kloudstack-azure-observability'
                )
            );
        }, 'admin.unconfigured_notice'));
    }
}
