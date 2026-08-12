<?php

declare(strict_types=1);

namespace KloudStack\Observability\Support;

use Throwable;

defined('ABSPATH') || exit;

/**
 * Debug logging.
 *
 * Silent unless WP_DEBUG is on AND the plugin's debug setting is enabled. Both gates matter: a
 * telemetry plugin filling a customer's log is its own kind of production incident, and neither
 * condition is reached by accident.
 *
 * Output goes to WordPress via error_log(), so WP_DEBUG_LOG decides the destination. The plugin
 * writes no files of its own — see write().
 */
final class Log
{
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
        if (!self::enabled()) {
            return;
        }

        // Handed to WordPress rather than written to a file of our own. WP_DEBUG_LOG decides where
        // it lands — wp-content/debug.log, or the server's error log — which is the destination the
        // site owner already chose and already knows how to read. On a KloudStack stack that is
        // /home/LogFiles, so it appears in Log Stream beside everything else.
        //
        // This replaced a plugin-managed file under uploads. That file needed a slug subfolder, an
        // .htaccess, an index.php and finally a per-site random filename — because .htaccess is
        // ignored by nginx and a fixed name is identical on every install of this plugin. All of it
        // existed to reproduce something WordPress already does, and each part was another way to
        // get file writes wrong. A plugin that writes no files cannot write one to the wrong place.
        error_log(sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            '[kloudstack-obs] %s: %s%s',
            $level,
            $message,
            $context === [] ? '' : ' ' . wp_json_encode($context)
        ));
    }
}
