<?php

declare(strict_types=1);

namespace KloudStack\Observability\Context;

use KloudStack\Observability\Telemetry\Envelope;

defined('ABSPATH') || exit;

/**
 * WordPress custom dimensions — schema v1 (functional specification section 8.1).
 *
 * These dimensions are what make the telemetry recognisably WordPress rather than generic HTTP.
 * They are also a published contract: Marketplace workbook queries bind to these names, so
 * renaming or removing one is a breaking change requiring a schema version increment.
 *
 * Everything here must be cheap. This runs on every tracked request, so no database queries are
 * issued and no expensive WordPress APIs are called — values come from constants, already-loaded
 * globals and in-memory state.
 */
final class WordPressContext
{
    public const CONTEXT_FRONT = 'front';
    public const CONTEXT_ADMIN = 'admin';
    public const CONTEXT_AJAX  = 'ajax';
    public const CONTEXT_REST  = 'rest';
    public const CONTEXT_CRON  = 'cron';
    public const CONTEXT_LOGIN = 'login';
    public const CONTEXT_CLI   = 'cli';

    /** @var int */
    private $schemaVersion;

    /** @var string */
    private $pluginVersion;

    /** @var string */
    private $loadMode;

    /** @var bool */
    private $managed;

    public function __construct(
        int $schemaVersion,
        string $pluginVersion,
        string $loadMode,
        bool $managed = false
    ) {
        $this->schemaVersion = $schemaVersion;
        $this->pluginVersion = $pluginVersion;
        $this->loadMode      = $loadMode;
        $this->managed       = $managed;
    }

    /**
     * Dimensions available at request start.
     *
     * Split from the runtime dimensions because these are stable for the whole request and can be
     * computed once, while memory and query counts are only meaningful at shutdown.
     *
     * @return array<string, string>
     */
    public function staticDimensions(): array
    {
        $dimensions = [
            'schema_version' => Envelope::dimension($this->schemaVersion),
            'plugin_version' => $this->pluginVersion,
            'load_mode'      => $this->loadMode,
            'php_version'    => PHP_VERSION,
            'wp_context'     => self::requestContext(),
        ];

        if ($this->managed) {
            $dimensions['managed_by'] = 'kloudstack';
        }

        $version = self::wordPressVersion();

        if ($version !== '') {
            $dimensions['wp_version'] = $version;
        }

        $theme = self::activeTheme();

        if ($theme !== '') {
            $dimensions['wp_theme'] = $theme;
        }

        $pluginCount = self::activePluginCount();

        if ($pluginCount !== null) {
            $dimensions['wp_plugin_count'] = Envelope::dimension($pluginCount);
        }

        if (function_exists('is_multisite') && is_multisite()) {
            $dimensions['wp_is_multisite'] = Envelope::dimension(true);
            $dimensions['wp_blog_id']      = Envelope::dimension(get_current_blog_id());
        }

        return $dimensions;
    }

    /**
     * Dimensions only meaningful once the request has finished doing its work.
     *
     * @return array<string, string>
     */
    public function runtimeDimensions(): array
    {
        $dimensions = [
            'wp_memory_peak_mb'  => Envelope::dimension(round(memory_get_peak_usage(true) / 1048576, 2)),
            'wp_user_logged_in'  => Envelope::dimension(self::isUserLoggedIn()),
        ];

        $queries = self::queryCount();

        if ($queries !== null) {
            $dimensions['wp_query_count'] = Envelope::dimension($queries);
        }

        $dimensions['wp_cache_hit'] = self::objectCacheState();

        return $dimensions;
    }

    /**
     * All schema v1 WordPress dimensions.
     *
     * @return array<string, string>
     */
    public function dimensions(): array
    {
        return array_merge($this->staticDimensions(), $this->runtimeDimensions());
    }

    /**
     * Classify the request.
     *
     * Order matters: an admin-ajax request satisfies both is_admin() and DOING_AJAX, and a cron
     * run satisfies neither reliably. The most specific classification must win, because these
     * values drive the default exclusion rules — misclassifying cron as front-end traffic would
     * pollute every performance percentile with background work.
     */
    public static function requestContext(): string
    {
        if (defined('WP_CLI') && constant('WP_CLI')) {
            return self::CONTEXT_CLI;
        }

        if (defined('DOING_CRON') && constant('DOING_CRON')) {
            return self::CONTEXT_CRON;
        }

        if (defined('REST_REQUEST') && constant('REST_REQUEST')) {
            return self::CONTEXT_REST;
        }

        if (defined('DOING_AJAX') && constant('DOING_AJAX')) {
            return self::CONTEXT_AJAX;
        }

        if (defined('XMLRPC_REQUEST') && constant('XMLRPC_REQUEST')) {
            return self::CONTEXT_AJAX;
        }

        // wp-login.php does not set a constant; it is identified by script name. Worth separating
        // because login traffic has a completely different performance and security profile from
        // the rest of the front end.
        if (self::isLoginRequest()) {
            return self::CONTEXT_LOGIN;
        }

        if (function_exists('is_admin') && is_admin()) {
            return self::CONTEXT_ADMIN;
        }

        return self::CONTEXT_FRONT;
    }

    private static function isLoginRequest(): bool
    {
        $script = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME'])
            ? basename(sanitize_text_field(wp_unslash($_SERVER['SCRIPT_NAME'])))
            : '';

        return $script === 'wp-login.php' || $script === 'wp-signup.php';
    }

    private static function wordPressVersion(): string
    {
        // $wp_version is a global rather than a function call, so this costs nothing.
        if (isset($GLOBALS['wp_version']) && is_string($GLOBALS['wp_version'])) {
            return $GLOBALS['wp_version'];
        }

        return '';
    }

    private static function activeTheme(): string
    {
        if (!function_exists('get_stylesheet')) {
            return '';
        }

        $stylesheet = get_stylesheet();

        return is_string($stylesheet) ? $stylesheet : '';
    }

    /**
     * Number of active plugins.
     *
     * Read from the already-populated option cache rather than counting anything, so no database
     * query is issued. Returns null when unavailable rather than guessing zero — a false zero
     * would read as "no plugins active", which is a meaningful and wrong claim.
     */
    private static function activePluginCount(): ?int
    {
        if (!function_exists('get_option')) {
            return null;
        }

        $active = get_option('active_plugins');

        if (!is_array($active)) {
            return null;
        }

        return count($active);
    }

    private static function queryCount(): ?int
    {
        if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb'])) {
            return null;
        }

        $wpdb = $GLOBALS['wpdb'];

        return isset($wpdb->num_queries) && is_int($wpdb->num_queries) ? $wpdb->num_queries : null;
    }

    private static function isUserLoggedIn(): bool
    {
        // Only report identity as a boolean. Who the user is never leaves the site by default —
        // see the functional specification section 7.
        return function_exists('is_user_logged_in') && is_user_logged_in();
    }

    /**
     * Whether a persistent object cache is serving this request.
     *
     * Returns "unknown" rather than "false" when it cannot be determined. On a site with no
     * persistent cache the distinction between "no cache configured" and "cache missed" matters
     * when reading a workbook, and conflating them makes cache hit-rate charts meaningless.
     */
    private static function objectCacheState(): string
    {
        if (!function_exists('wp_using_ext_object_cache')) {
            return 'unknown';
        }

        if (!wp_using_ext_object_cache()) {
            return 'unknown';
        }

        if (!isset($GLOBALS['wp_object_cache']) || !is_object($GLOBALS['wp_object_cache'])) {
            return 'unknown';
        }

        $cache = $GLOBALS['wp_object_cache'];

        if (!isset($cache->cache_hits, $cache->cache_misses)) {
            return 'unknown';
        }

        $hits   = (int) $cache->cache_hits;
        $misses = (int) $cache->cache_misses;

        if ($hits + $misses === 0) {
            return 'unknown';
        }

        return Envelope::dimension($hits > $misses);
    }
}
