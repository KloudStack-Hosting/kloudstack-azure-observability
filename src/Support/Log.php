<?php

declare(strict_types=1);

namespace KloudStack\Observability\Support;

use Throwable;

defined('ABSPATH') || exit;

/**
 * Debug logging.
 *
 * Silent unless WP_DEBUG is on and the plugin's debug setting is enabled. Never writes to the
 * PHP error log by default: a telemetry plugin filling a customer's error log is its own kind of
 * production incident.
 */
final class Log
{
    private const FILENAME = 'kloudstack-obs-debug.log';

    /** @var bool|null */
    private static $enabled = null;

    public static function enabled(): bool
    {
        if (self::$enabled === null) {
            self::$enabled = defined('WP_DEBUG')
                && WP_DEBUG
                && (bool) apply_filters('kloudstack_obs_debug_log', false);
        }

        return self::$enabled;
    }

    /**
     * Reset the memoised state. Test seam.
     */
    public static function reset(): void
    {
        self::$enabled = null;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function debug(string $message, array $context = []): void
    {
        self::write('DEBUG', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function exception(Throwable $e, string $context): void
    {
        self::write('EXCEPTION', $e->getMessage(), [
            'context' => $context,
            'type'    => get_class($e),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function write(string $level, string $message, array $context): void
    {
        if (!self::enabled() || !defined('WP_CONTENT_DIR')) {
            return;
        }

        $line = sprintf(
            "[%s] %s: %s%s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            $level,
            $message,
            $context === [] ? '' : ' ' . wp_json_encode($context)
        );

        // Errors here are deliberately ignored — a failed debug write must never escalate.
        @file_put_contents(WP_CONTENT_DIR . '/' . self::FILENAME, $line, FILE_APPEND | LOCK_EX);
    }
}