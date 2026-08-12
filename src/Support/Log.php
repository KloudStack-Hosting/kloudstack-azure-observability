<?php

declare(strict_types=1);

namespace KloudStack\Observability\Support;

use Throwable;

// Sub-namespace: PHP falls back to the GLOBAL namespace for an unqualified constant, never to
// the parent, so PREFIX must be imported explicitly here even though Settings.php uses it bare.
use const KloudStack\Observability\PREFIX;

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
    /** Subfolder of uploads, named for the plugin slug as WordPress.org requires. */
    private const DIRNAME = 'kloudstack-azure-observability';

    /**
     * Filename stem. The real filename carries a random suffix — see filename().
     */
    private const FILENAME_STEM = 'kloudstack-obs-debug';

    /** Option holding this site's random filename suffix. */
    private const KEY_OPTION = PREFIX . 'log_key';

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
        if (!self::enabled() || !function_exists('wp_upload_dir')) {
            return;
        }

        // Write beneath the uploads directory — resolved at runtime via wp_upload_dir(), never a
        // hard-coded path, and never the plugin folder, which is deleted on upgrade.
        //
        // The log lives in a subfolder named for the plugin slug, not directly in the uploads
        // root. WordPress.org requires the slug subfolder, and the root is worse than untidy: a
        // file there is served straight off the web at a guessable URL, and a debug log holds
        // request paths and error messages.
        $dir = self::directory();

        if ($dir === null) {
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
        @file_put_contents($dir . '/' . self::filename(), $line, FILE_APPEND | LOCK_EX); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }

    /**
     * Log filename, carrying a per-site random suffix.
     *
     * The .htaccess written alongside it denies direct access — on Apache. nginx ignores
     * .htaccess entirely, and a large share of WordPress is served by nginx, so on those sites the
     * guard file is present and inert: the log is fetchable by anyone who guesses the path. A
     * fixed name is trivially guessable, since it is the same on every install of this plugin.
     *
     * An unguessable suffix closes that without depending on the web server. Generated once and
     * stored, so the path is stable for whoever needs to read it. Not a substitute for the
     * .htaccess — both are kept, and each covers what the other cannot.
     */
    private static function filename(): string
    {
        $key = (string) get_option(self::KEY_OPTION, '');

        if ($key === '') {
            $key = wp_generate_password(20, false, false);
            update_option(self::KEY_OPTION, $key, false);
        }

        return self::FILENAME_STEM . '-' . $key . '.log';
    }

    /**
     * The plugin's log directory beneath uploads, created and protected on first use.
     *
     * Returns null when uploads is unavailable or not writable — on those hosts debug logging is
     * simply unavailable, which is the correct outcome for an optional diagnostic.
     */
    private static function directory(): ?string
    {
        static $resolved = false;
        static $dir      = null;

        if ($resolved) {
            return $dir;
        }

        $resolved = true;

        $uploads = wp_upload_dir();
        if (!is_array($uploads) || !empty($uploads['error']) || empty($uploads['basedir'])) {
            return null;
        }

        $candidate = rtrim((string) $uploads['basedir'], '/\\') . '/' . self::DIRNAME;

        if (!is_dir($candidate) && !wp_mkdir_p($candidate)) {
            return null;
        }

        // Deny direct access. .htaccess covers Apache; index.php stops directory listings and
        // blank-page probes on servers that ignore it. Neither is a substitute for the file being
        // outside the web root, but the uploads directory is the only location plugins may write
        // to, so this is the available mitigation.
        self::protect($candidate);

        $dir = $candidate;

        return $dir;
    }

    private static function protect(string $dir): void
    {
        $guards = [
            '.htaccess' => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'index.php' => "<?php\n// Silence is golden.\n",
        ];

        foreach ($guards as $name => $contents) {
            $path = $dir . '/' . $name;

            if (file_exists($path)) {
                continue;
            }

            // Same rule as the log write itself: a failed guard must never escalate.
            @file_put_contents($path, $contents, LOCK_EX); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
    }
}
