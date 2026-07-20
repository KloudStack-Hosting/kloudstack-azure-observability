<?php

declare(strict_types=1);

namespace KloudStack\Observability\Support;

use Throwable;

defined('ABSPATH') || exit;

/**
 * Fail-closed wrapper for every callback this plugin registers with WordPress.
 *
 * This is the most important structural decision in the plugin. Telemetry collection touches
 * error handlers, shutdown handlers, the HTTP stack and third-party plugin internals — all
 * places where an unexpected value is likely. An observability plugin that takes a customer's
 * site down is strictly worse than no observability plugin at all, so no code path here is
 * permitted to surface a Throwable into WordPress.
 */
final class Guard
{
    /**
     * Wrap a callable so that any Throwable it raises is swallowed and logged.
     *
     * @param callable $callback  The callback to protect.
     * @param string   $context   Short label used in debug output.
     * @param mixed    $onFailure Value returned if the callback throws.
     */
    public static function wrap(callable $callback, string $context, $onFailure = null): callable
    {
        return static function (...$args) use ($callback, $context, $onFailure) {
            try {
                return $callback(...$args);
            } catch (Throwable $e) {
                Log::exception($e, $context);

                return $onFailure;
            }
        };
    }

    /**
     * Execute a callable immediately, returning a fallback if it throws.
     *
     * Used for inline collection work that is not registered as a hook.
     *
     * @param mixed $onFailure Value returned if the callback throws.
     *
     * @return mixed
     */
    public static function run(callable $callback, string $context, $onFailure = null)
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            Log::exception($e, $context);

            return $onFailure;
        }
    }
}