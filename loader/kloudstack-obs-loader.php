<?php

/**
 * Plugin Name: KloudStack Observability Loader
 * Description: Loads KloudStack Observability for Azure at MU priority on KloudStack managed stacks.
 * Version:     2.0.0
 * Author:      KloudStack
 *
 * Deployed to wp-content/mu-plugins/ by the KloudStack WordPress image. This is the only
 * KloudStack-specific code in the project, and it ships in the public repository so that there
 * is genuinely one codebase.
 *
 * Loading at MU priority is not cosmetic: it is what allows the exception collector to capture
 * fatal errors raised while regular plugins load. A standard plugin cannot do that.
 *
 * @package KloudStack\Observability
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

(static function (): void {
    $plugin = WP_PLUGIN_DIR . '/kloudstack-azure-observability/kloudstack-azure-observability.php';

    if (!is_readable($plugin)) {
        return;
    }

    /*
     * Remove the legacy 1.x MU-plugin if the image upgrade left it behind.
     *
     * Both versions register their own shutdown handler, so leaving 1.x in place would double
     * every request's telemetry — and double the customer's ingestion cost — while looking
     * superficially fine. Delete this block once the fleet is confirmed clear of 1.x.
     */
    $legacy = WPMU_PLUGIN_DIR . '/kloudstack-appinsights.php';

    if (file_exists($legacy)) {
        // wp_delete_file() is unavailable this early on some configurations; fall back to unlink.
        // Failure is deliberately silent — a read-only filesystem must not break the site, and
        // the diagnostics self-test reports the duplicate if removal did not succeed.
        if (function_exists('wp_delete_file')) {
            wp_delete_file($legacy);
        } else {
            @unlink($legacy); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
    }

    // Tell the bootstrap not to self-register on plugins_loaded; we boot it directly below.
    define('KLOUDSTACK_OBS_DEFER_BOOT', true);

    // Managed mode: suppresses auto-update, hides connection configuration in the admin, and
    // tags telemetry as fleet-managed.
    if (!defined('KLOUDSTACK_OBS_MANAGED')) {
        define('KLOUDSTACK_OBS_MANAGED', true);
    }

    require_once $plugin;

    \KloudStack\Observability\kloudstack_obs()->boot();

    /*
     * Suppress update notices and auto-updates for the managed copy. The image controls the
     * version; an out-of-band update from WordPress.org would drift a stack away from its
     * pinned, tested release.
     */
    add_filter('auto_update_plugin', static function ($update, $item) {
        if (isset($item->slug) && $item->slug === 'kloudstack-azure-observability') {
            return false;
        }

        return $update;
    }, 10, 2);
})();
