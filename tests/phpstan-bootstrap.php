<?php

/**
 * PHPStan bootstrap.
 *
 * Defines the constants the plugin declares at runtime so static analysis can resolve them.
 * WordPress core constants are guarded because php-stubs/wordpress-stubs already defines them.
 */

declare(strict_types=1);

defined('ABSPATH') || define('ABSPATH', '/wordpress/');
defined('WP_PLUGIN_DIR') || define('WP_PLUGIN_DIR', '/wordpress/wp-content/plugins');
defined('WPMU_PLUGIN_DIR') || define('WPMU_PLUGIN_DIR', '/wordpress/wp-content/mu-plugins');
defined('WP_CONTENT_DIR') || define('WP_CONTENT_DIR', '/wordpress/wp-content');

// Plugin constants, defined by the bootstrap at runtime.
defined('KloudStack\\Observability\\PLUGIN_FILE') || define(
    'KloudStack\\Observability\\PLUGIN_FILE',
    '/wordpress/wp-content/plugins/kloudstack-azure-observability/kloudstack-azure-observability.php'
);
defined('KloudStack\\Observability\\PLUGIN_DIR') || define(
    'KloudStack\\Observability\\PLUGIN_DIR',
    '/wordpress/wp-content/plugins/kloudstack-azure-observability/'
);
defined('KloudStack\\Observability\\PLUGIN_URL') || define(
    'KloudStack\\Observability\\PLUGIN_URL',
    'https://example.com/wp-content/plugins/kloudstack-azure-observability/'
);
