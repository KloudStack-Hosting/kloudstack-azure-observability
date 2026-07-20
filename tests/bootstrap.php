<?php

/**
 * Unit test bootstrap.
 *
 * These tests exercise pure logic — configuration resolution, envelope construction, privacy
 * scrubbing, sampling — without loading WordPress. The handful of WordPress functions that logic
 * touches are stubbed here.
 *
 * Anything that genuinely needs WordPress belongs in tests/integration, which runs under wp-env.
 */

declare(strict_types=1);

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

// Namespace-level constants normally defined by the plugin bootstrap, which unit tests do not
// load because it registers WordPress hooks.
if (!defined('KloudStack\\Observability\\PREFIX')) {
    define('KloudStack\\Observability\\PREFIX', 'kloudstack_obs_');
}

if (!defined('KloudStack\\Observability\\VERSION')) {
    define('KloudStack\\Observability\\VERSION', '2.0.0-test');
}

if (!defined('KloudStack\\Observability\\SCHEMA_VERSION')) {
    define('KloudStack\\Observability\\SCHEMA_VERSION', 1);
}

if (!defined('KloudStack\\Observability\\SLUG')) {
    define('KloudStack\\Observability\\SLUG', 'kloudstack-azure-observability');
}

if (!defined('KloudStack\\Observability\\PLUGIN_URL')) {
    define('KloudStack\\Observability\\PLUGIN_URL', 'https://example.com/wp-content/plugins/kloudstack-azure-observability/');
}
