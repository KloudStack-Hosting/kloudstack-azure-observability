<?php

declare(strict_types=1);

namespace KloudStack\Observability\Client;

use KloudStack\Observability\Config;
use KloudStack\Observability\Context\AzureContext;
use KloudStack\Observability\Context\Correlation;
use KloudStack\Observability\Settings;
use KloudStack\Observability\Support\Guard;
use KloudStack\Observability\Telemetry\Privacy;

defined('ABSPATH') || exit;

/**
 * Application Insights JavaScript SDK injection.
 *
 * Uses Microsoft's official loader snippet (src/Client/snippet.js), the same loader 1.x used.
 * An intermediate version replaced it with a hand-rolled script tag and a direct
 * `new ApplicationInsights()`. That produced payloads Azure accepted -- itemsAccepted:1,
 * errors:[], correct endpoint, correct key, correlation tags present -- which were then never
 * indexed, while a site on the identical component and SDK version using the official snippet
 * indexed normally. The supported loader is used rather than continuing to guess at why.
 *
 * What still differs from 1.x, and is the point of this class:
 *
 * 1. A telemetry initializer, registered through the snippet's onInit hook, stamps the server
 *    request's operation id onto browser telemetry so a page view can be joined to the request
 *    that produced it. Without it the two halves of the same page load are unrelated rows.
 *
 * 2. Request and response header tracking are off unless explicitly enabled. 1.x turned both on,
 *    which sends cookies and authorization headers to Azure with no disclosure.
 */
final class SnippetInjector
{
    private const CDN_URL = 'https://js.monitor.azure.com/scripts/b/ai.3.gbl.min.js';

    /** @var Config */
    private $config;

    /** @var Settings */
    private $settings;

    /** @var Correlation */
    private $correlation;

    /** @var AzureContext */
    private $azure;

    public function __construct(
        Config $config,
        Settings $settings,
        Correlation $correlation,
        AzureContext $azure
    ) {
        $this->config      = $config;
        $this->settings    = $settings;
        $this->correlation = $correlation;
        $this->azure       = $azure;
    }

    public function register(): void
    {
        add_action('wp_head', Guard::wrap([$this, 'render'], 'client.wp_head'), 2);
    }

    /**
     * Whether the SDK should be injected for this request.
     */
    public function shouldInject(): bool
    {
        if (!$this->settings->bool('client_enabled') || !$this->config->isConfigured()) {
            return false;
        }

        // The SDK sets ai_user and ai_session cookies, which in most jurisdictions require
        // consent. A consent-management plugin gates injection through this filter.
        return Privacy::hasConsent();
    }

    /**
     * SDK configuration.
     *
     * @return array<string, mixed>
     */
    public function sdkConfig(): array
    {
        $credentials = $this->config->credentials();

        $config = [];

        if (($credentials['connection_string'] ?? '') !== '') {
            $config['connectionString'] = $credentials['connection_string'];
        } else {
            $config['instrumentationKey'] = $credentials['ikey'] ?? '';
        }

        $config['enableAutoRouteTracking'] = true;
        $config['disableAjaxTracking']     = false;
        $config['enableCorsCorrelation']   = true;

        // Off unless opted in — enabling these transmits cookies and authorization headers.
        $headerTracking                       = $this->settings->bool('header_tracking');
        $config['enableRequestHeaderTracking']  = $headerTracking;
        $config['enableResponseHeaderTracking'] = $headerTracking;

        if ($this->settings->bool('cookieless')) {
            // Removes the consent obligation, at the cost of session and user aggregation.
            $config['disableCookiesUsage'] = true;
        }

        $samplingRate = $this->settings->samplingRate();

        if ($samplingRate < 100) {
            // The JS SDK expects a percentage; matching the server rate keeps the two halves of
            // a page load sampled consistently.
            $config['samplingPercentage'] = $samplingRate;
        }

        return (array) apply_filters('kloudstack_obs_client_config', $config);
    }

    /**
     * Render the snippet.
     */
    public function render(): void
    {
        if (!$this->shouldInject()) {
            return;
        }

        $configJson = wp_json_encode($this->sdkConfig(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($configJson)) {
            return;
        }

        $context = wp_json_encode([
            'operationId' => $this->correlation->operationId(),
            'parentId'    => $this->correlation->parentId(),
            'role'        => $this->azure->role(),
        ], JSON_UNESCAPED_SLASHES);

        if (!is_string($context)) {
            return;
        }

        $source = $this->settings->bool('bundled_sdk')
            ? \KloudStack\Observability\PLUGIN_URL . 'src/Client/assets/ai.3.gbl.min.js'
            : self::CDN_URL;

        $snippet = self::loaderSnippet();

        if ($snippet === '') {
            return;
        }

        /*
         * Delivered through the script API rather than echoed as a raw <script> tag, per the
         * WordPress.org requirement to enqueue all JavaScript.
         *
         * The handle is registered with no src on purpose. Microsoft's loader snippet is a
         * bootstrapper: it fetches the SDK itself, asynchronously, from either the CDN or the
         * bundled copy depending on the bundled_sdk setting. Enqueuing that URL directly would
         * load the SDK twice. A src-less handle gives us a legitimate carrier for the inline
         * code while leaving the loader in charge of fetching.
         *
         * Priority 2 on wp_head still applies (see register()), so the SDK initialises early
         * enough to capture page-load timings.
         *
         * wp_json_encode produces valid JSON with everything quoted and escaped, which is safe
         * inside an inline script. esc_js() must NOT be used here: it HTML-encodes ampersands,
         * which corrupts connection strings and breaks the SDK. The 1.x plugin documented the
         * same trap.
         */
        $handle = 'kloudstack-obs';

        wp_register_script($handle, false, [], \KloudStack\Observability\VERSION, false);
        wp_enqueue_script($handle);

        wp_add_inline_script(
            $handle,
            'window.kloudstackObs = ' . $context . ';' . "\n"
                . $snippet . "\n"
                . self::loaderInvocation($source, $configJson)
        );

        self::registerNonceFilter($handle);
    }

    /**
     * Apply the caller-supplied CSP nonce to our inline script tag.
     *
     * Previously the nonce was concatenated into a hand-built <script> tag. Inline scripts added
     * through wp_add_inline_script are printed by WordPress, so the attribute has to be attached
     * via the wp_script_attributes filter (WordPress 5.7+) instead.
     */
    private static function registerNonceFilter(string $handle): void
    {
        $nonce = (string) apply_filters('kloudstack_obs_script_nonce', '');

        if ($nonce === '') {
            return;
        }

        add_filter('wp_script_attributes', static function (array $attributes) use ($handle, $nonce): array {
            return self::applyNonce($attributes, $handle, $nonce);
        });
    }

    /**
     * Add the nonce to our own script tags only.
     *
     * WordPress ids inline script tags as "<handle>-js-after" / "-js-before", so matching the
     * handle prefix keeps the nonce off every other plugin's scripts.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    private static function applyNonce(array $attributes, string $handle, string $nonce): array
    {
        $id = isset($attributes['id']) ? (string) $attributes['id'] : '';

        if (strpos($id, $handle . '-js') === 0) {
            $attributes['nonce'] = $nonce;
        }

        return $attributes;
    }

    /**
     * Microsoft's official loader snippet, read from disk once per request.
     */
    private static function loaderSnippet(): string
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $path = __DIR__ . '/snippet.js';

        if (!is_readable($path)) {
            return $cached = '';
        }

        $contents = file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        return $cached = is_string($contents) ? $contents : '';
    }

    /**
     * The snippet's invocation object.
     *
     * onInit is why this is built here rather than encoded as JSON: it must be a function, and
     * it is the supported hook for registering a telemetry initializer before the queued page
     * view is flushed. The initializer stamps the server request's operation id onto browser
     * telemetry, which is what makes a page view joinable to the request that produced it.
     *
     * It is guarded and returns true explicitly: the SDK drops an item when an initializer throws
     * or returns false, so a fault in the code that adds correlation would silently destroy the
     * telemetry it exists to enrich.
     */
    private static function loaderInvocation(string $source, string $configJson): string
    {
        $src = wp_json_encode($source, JSON_UNESCAPED_SLASHES);

        // Built by concatenation rather than a heredoc: the WordPress.org Plugin Check disallows
        // heredoc/nowdoc syntax. $src and $configJson are already JSON-encoded; the rest is a static
        // template containing only double-quoted JS strings, so a single-quoted PHP string is exact.
        return '({
    src: ' . $src . ',
    crossOrigin: "anonymous",
    cfg: ' . $configJson . ',
    onInit: function (sdk) {
        var ctx = window.kloudstackObs || {};

        try {
            sdk.addTelemetryInitializer(function (item) {
                try {
                    if (item && item.tags) {
                        if (ctx.operationId) {
                            item.tags["ai.operation.id"] = ctx.operationId;
                        }
                        if (ctx.parentId) {
                            item.tags["ai.operation.parentId"] = ctx.parentId;
                        }
                        if (ctx.role) {
                            item.tags["ai.cloud.role"] = ctx.role;
                        }
                    }
                } catch (e) {
                    if (window.console && window.console.error) {
                        window.console.error("[KloudStack Observability] initializer:", e);
                    }
                }

                return true;
            });

            ctx.ready = true;
        } catch (e) {
            ctx.error = { stage: "add-initializer", message: e && e.message ? e.message : String(e) };

            if (window.console && window.console.error) {
                window.console.error("[KloudStack Observability] add-initializer:", e);
            }
        }
    }
})';
    }
}
