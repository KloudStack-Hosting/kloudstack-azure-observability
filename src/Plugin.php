<?php

declare(strict_types=1);

namespace KloudStack\Observability;

use KloudStack\Observability\Support\Guard;
use KloudStack\Observability\Support\Log;

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

    /** @var bool */
    private $booted = false;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? new Config();
    }

    public function config(): Config
    {
        return $this->config;
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
            $this->loadTextDomain();
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

    private function loadTextDomain(): void
    {
        load_plugin_textdomain(
            SLUG,
            false,
            dirname(plugin_basename(PLUGIN_FILE)) . '/languages'
        );
    }

    /**
     * Master kill switch, independent of whether credentials are present.
     */
    private function isTelemetryEnabled(): bool
    {
        $enabled = (bool) get_option(PREFIX . 'enabled', true);

        return (bool) apply_filters('kloudstack_obs_setting_enabled', $enabled);
    }

    /**
     * Collector registration.
     *
     * Phase D of the implementation plan. Deliberately left unwired so that Phase A can be
     * merged, reviewed and released independently of telemetry collection.
     */
    private function registerCollectors(): void
    {
        // RequestCollector, ExceptionCollector, ErrorCollector, SnippetInjector — Phase D.
        Log::debug('Configured and enabled; collectors pending Phase D.', [
            'source'    => $this->config->source(),
            'load_mode' => $this->loadMode(),
        ]);
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

        // SettingsPage, Diagnostics, SiteHealth — Phase E.
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