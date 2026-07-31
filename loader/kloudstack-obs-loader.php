<?php

/**
 * Plugin Name: KloudStack Observability Loader
 * Description: Boots KloudStack Observability for Azure at MU priority when it is active.
 * Version:     2.1.0
 * Author:      KloudStack
 *
 * Deployed to wp-content/mu-plugins/ by the KloudStack WordPress image. This is the only
 * KloudStack-specific code in the project, and it ships in the public repository so that there
 * is genuinely one codebase.
 *
 * Loading at MU priority is not cosmetic: it is what allows the exception collector to capture
 * fatal errors raised while regular plugins load. A standard plugin cannot do that.
 *
 * It boots the plugin ONLY when the plugin is active in WordPress. Activation and deactivation
 * therefore behave exactly as they do for any plugin — a site owner (or KloudStack) can turn
 * observability off from the Plugins screen and it genuinely stops — while an active plugin still
 * gets the early-load benefit. Earlier versions booted unconditionally, which removed that control.
 *
 * @package KloudStack\Observability
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

(static function (): void {
    $plugin   = WP_PLUGIN_DIR . '/kloudstack-azure-observability/kloudstack-azure-observability.php';
    $basename = 'kloudstack-azure-observability/kloudstack-azure-observability.php';

    if (!is_readable($plugin)) {
        return;
    }

    /*
     * Remove the legacy 1.x MU-plugin if the image upgrade left it behind. Done regardless of the
     * new plugin's activation state — a stray 1.x would double telemetry whether or not 2.x runs.
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

    /*
     * Respect the WordPress activation state. mu-plugins load before regular plugins, but the
     * options table is already available, so active_plugins can be read here. If the plugin has
     * been deactivated from the Plugins screen, do nothing — WordPress will not load it either,
     * so observability is genuinely off until it is reactivated.
     */
    if (!function_exists('get_option')) {
        return;
    }

    $active = (array) get_option('active_plugins', array());

    if (!in_array($basename, $active, true)) {
        return;
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
