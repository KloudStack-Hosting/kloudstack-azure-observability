<?php

declare(strict_types=1);

/**
 * Unit test bootstrap.
 *
 * These tests exercise pure logic — configuration resolution, envelope construction, privacy
 * scrubbing, sampling — without loading WordPress. The handful of WordPress functions that logic
 * touches are stubbed here.
 *
 * Anything that genuinely needs WordPress belongs in tests/integration, which runs under wp-env.
 */

define('ABSPATH', __DIR__ . '/');

require_once __DIR__ . '/stubs/wordpress.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'KloudStack\\Observability\\';
    $length = strlen($prefix);

    if (strncmp($prefix, $class, $length) !== 0) {
        return;
    }

    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, $length)) . '.php';

    if (is_readable($path)) {
        require_once $path;
    }
});

// Namespace-level constants normally defined by the plugin bootstrap.
if (!defined('KloudStack\\Observability\\PREFIX')) {
    define('KloudStack\\Observability\\PREFIX', 'kloudstack_obs_');
}