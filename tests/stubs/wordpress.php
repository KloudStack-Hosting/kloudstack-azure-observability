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

    public static function reset(): void
    {
        self::$options = [];
        self::$filters = [];
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