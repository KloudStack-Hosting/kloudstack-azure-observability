<?php

declare(strict_types=1);

/**
 * PHPStan bootstrap.
 *
 * Defines the constants the plugin declares at runtime so static analysis can resolve them.
 */

define('ABSPATH', '/wordpress/');
define('WP_PLUGIN_DIR', '/wordpress/wp-content/plugins');
define('WPMU_PLUGIN_DIR', '/wordpress/wp-content/mu-plugins');
define('WP_CONTENT_DIR', '/wordpress/wp-content');

define('KloudStack\Observability\PLUGIN_FILE', '/wordpress/wp-content/plugins/kloudstack-azure-observability/kloudstack-azure-observability.php');
define('KloudStack\Observability\PLUGIN_DIR', '/wordpress/wp-content/plugins/kloudstack-azure-observability/');
define('KloudStack\Observability\PLUGIN_URL', 'https://example.com/wp-content/plugins/kloudstack-azure-observability/');
