<?php

declare(strict_types=1);

/**
 * Minimal WordPress function stubs for unit tests.
 *
 * Deliberately small. If a class under test needs more than this, that is a signal it belongs in
 * the integration suite rather than the unit suite.
 */

final class WPStubs
{
    /** @var array<string, mixed> */
    public static $options = [];

    /** @var array<string, callable> */
    public static $filters = [];

    /** @var bool */
    public static $isAdmin = false;

    /** @var bool */
    public static $isMultisite = false;

    /** @var int */
    public static $blogId = 1;

    /** @var string */
    public static $stylesheet = '';

    /** @var bool */
    public static $userLoggedIn = false;

    /** @var bool */
    public static $usingExtObjectCache = false;

    /** @var array<string, mixed> */
    public static $transients = [];

    /** @var array<string, array<int, callable>> */
    public static $actions = [];

    /** @var string */
    public static $homeUrl = 'https://example.com';

    public static function reset(): void
    {
        self::$options             = [];
        self::$filters             = [];
        self::$isAdmin             = false;
        self::$isMultisite         = false;
        self::$blogId              = 1;
        self::$stylesheet          = '';
        self::$userLoggedIn        = false;
        self::$usingExtObjectCache = false;
        self::$transients          = [];
        self::$actions             = [];
        self::$homeUrl             = 'https://example.com';
    }
}

if (!function_exists('get_option')) {
    /**
     * @param mixed $default
     *
     * @return mixed
     */
    function get_option(string $option, $default = false)
    {
        return WPStubs::$options[$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    /**
     * @param mixed $value
     */
    function update_option(string $option, $value): bool
    {
        WPStubs::$options[$option] = $value;

        return true;
    }
}

if (!function_exists('apply_filters')) {
    /**
     * @param mixed $value
     * @param mixed ...$args
     *
     * @return mixed
     */
    function apply_filters(string $hook, $value, ...$args)
    {
        if (isset(WPStubs::$filters[$hook])) {
            return (WPStubs::$filters[$hook])($value, ...$args);
        }

        return $value;
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback): bool
    {
        WPStubs::$filters[$hook] = $callback;

        return true;
    }
}

if (!function_exists('wp_json_encode')) {
    /**
     * @param mixed $data
     *
     * @return string|false
     */
    function wp_json_encode($data, int $flags = 0)
    {
        return json_encode($data, $flags);
    }
}

if (!function_exists('wp_unslash')) {
    /**
     * @param mixed $value
     *
     * @return mixed
     */
    function wp_unslash($value)
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return WPStubs::$isAdmin;
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return WPStubs::$isMultisite;
    }
}

if (!function_exists('get_current_blog_id')) {
    function get_current_blog_id(): int
    {
        return WPStubs::$blogId;
    }
}

if (!function_exists('get_stylesheet')) {
    function get_stylesheet(): string
    {
        return WPStubs::$stylesheet;
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return WPStubs::$userLoggedIn;
    }
}

if (!function_exists('wp_using_ext_object_cache')) {
    function wp_using_ext_object_cache(): bool
    {
        return WPStubs::$usingExtObjectCache;
    }
}

if (!function_exists('get_transient')) {
    /**
     * @return mixed
     */
    function get_transient(string $transient)
    {
        return WPStubs::$transients[$transient] ?? false;
    }
}

if (!function_exists('set_transient')) {
    /**
     * @param mixed $value
     */
    function set_transient(string $transient, $value, int $expiration = 0): bool
    {
        WPStubs::$transients[$transient] = $value;

        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $transient): bool
    {
        unset(WPStubs::$transients[$transient]);

        return true;
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
    {
        WPStubs::$actions[$hook][] = $callback;

        return true;
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wp_parse_url')) {
    /**
     * @return mixed
     */
    function wp_parse_url(string $url, int $component = -1)
    {
        return parse_url($url, $component);
    }
}

if (!function_exists('home_url')) {
    function home_url(): string
    {
        return WPStubs::$homeUrl;
    }
}
