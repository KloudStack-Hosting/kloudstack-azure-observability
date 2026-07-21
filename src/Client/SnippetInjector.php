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
 * Two things distinguish this from the 1.x version:
 *
 * 1. A telemetry initializer stamps the server request's operation id onto browser telemetry, so
 *    a page view can be joined to the request that produced it. Without it the two halves of the
 *    same page load are unrelated rows.
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

        // Fully qualified: an unqualified reference would resolve to
        // KloudStack\Observability\Client\PLUGIN_URL, which does not exist, and the bundled-SDK
        // path would throw at runtime.
        $source = $this->settings->bool('bundled_sdk')
            ? \KloudStack\Observability\PLUGIN_URL . 'src/Client/assets/ai.3.gbl.min.js'
            : self::CDN_URL;

        $initializer = wp_json_encode([
            'operationId' => $this->correlation->operationId(),
            'parentId'    => $this->correlation->parentId(),
            'role'        => $this->azure->role(),
        ], JSON_UNESCAPED_SLASHES);

        if (!is_string($initializer)) {
            return;
        }

        $nonce     = (string) apply_filters('kloudstack_obs_script_nonce', '');
        $nonceAttr = $nonce !== '' ? ' nonce="' . esc_attr($nonce) . '"' : '';

        /*
         * wp_json_encode produces valid JSON with everything quoted and escaped, which is safe
         * inside an inline script. esc_js() must NOT be used here: it HTML-encodes ampersands,
         * which corrupts connection strings and breaks the SDK. The 1.x plugin documented the
         * same trap.
         *
         * phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
         */
        echo "\n<!-- KloudStack Observability for Azure -->\n";
        echo '<script type="text/javascript"' . $nonceAttr . ' src="' . esc_url($source) . '" async></script>' . "\n";
        echo '<script type="text/javascript"' . $nonceAttr . '>' . "\n";
        echo 'window.kloudstackObs = ' . $initializer . ';' . "\n";
        echo self::bootstrapScript($configJson);
        echo "</script>\n";
        echo "<!-- /KloudStack Observability for Azure -->\n";
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * The inline bootstrap.
     *
     * Waits for the asynchronously loaded SDK rather than assuming it is present, then registers
     * a telemetry initializer that stamps the server's operation id and cloud role onto every
     * browser item. That stamping is the entire point of this file: it is what makes a page view
     * joinable to its server request.
     */
    private static function bootstrapScript(string $configJson): string
    {
        return <<<JS
(function () {
    var cfg = {$configJson};
    var ctx = window.kloudstackObs || {};
    var attempts = 0;

    // Never throw into the page, but never fail invisibly either. An earlier version swallowed
    // every error with a bare catch, which meant the SDK could fail to record anything while the
    // console stayed clean — the one outcome an observability plugin must not have.
    function fail(stage, err) {
        ctx.error = { stage: stage, message: err && err.message ? err.message : String(err) };
        if (window.console && window.console.error) {
            window.console.error('[KloudStack Observability] ' + stage + ':', err);
        }
    }

    function start() {
        var sdk = window.Microsoft && window.Microsoft.ApplicationInsights;

        if (!sdk) {
            // The SDK loads async; retry briefly, then give up rather than polling forever.
            if (++attempts < 100) {
                window.setTimeout(start, 50);
                return;
            }

            fail('sdk-load', new Error('Application Insights SDK did not load. Blocked by an ad blocker, CSP or network policy?'));
            return;
        }

        var appInsights;

        try {
            appInsights = new sdk.ApplicationInsights({ config: cfg });
            appInsights.loadAppInsights();
        } catch (e) {
            fail('init', e);
            return;
        }

        try {
            appInsights.addTelemetryInitializer(function (item) {
                /*
                 * Guarded and explicitly truthy-returning.
                 *
                 * The SDK drops an item when an initializer throws OR returns false, so a fault
                 * here silently destroys the telemetry it was meant to enrich. Correlation is
                 * worth having; it is not worth losing the page view over.
                 *
                 * tags is deliberately not reassigned: ITelemetryItem.tags is typed Tags & Tags[]
                 * and replacing it with a plain object can break the SDK's own serialisation.
                 */
                try {
                    if (item && item.tags) {
                        if (ctx.operationId) {
                            item.tags['ai.operation.id'] = ctx.operationId;
                        }
                        if (ctx.parentId) {
                            item.tags['ai.operation.parentId'] = ctx.parentId;
                        }
                        if (ctx.role) {
                            item.tags['ai.cloud.role'] = ctx.role;
                        }
                    }
                } catch (e) {
                    fail('initializer', e);
                }

                return true;
            });
        } catch (e) {
            fail('add-initializer', e);
        }

        try {
            appInsights.trackPageView();
        } catch (e) {
            fail('track-page-view', e);
        }

        window.appInsights = appInsights;
        ctx.ready = true;
    }

    start();
})();
JS;
    }
}
