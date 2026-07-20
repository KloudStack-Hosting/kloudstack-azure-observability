<?php
/**
 * Uninstall handler.
 *
 * Removes every option and transient the plugin creates. Telemetry already sent to Application
 * Insights is not affected — it lives in the customer's Azure subscription and is theirs to
 * retain or delete under their own retention settings.
 *
 * @package KloudStack\Observability
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

$kloudstack_obs_options = array(
    'kloudstack_obs_connection_string',
    'kloudstack_obs_enabled',
    'kloudstack_obs_sampling_requests',
    'kloudstack_obs_sampling_dependencies',
    'kloudstack_obs_client_enabled',
    'kloudstack_obs_cloud_role',
    'kloudstack_obs_anonymise_ip',
    'kloudstack_obs_query_allowlist',
    'kloudstack_obs_header_tracking',
    'kloudstack_obs_excluded_paths',
    'kloudstack_obs_track_admin',
    'kloudstack_obs_track_cron',
    'kloudstack_obs_debug_log',
    'kloudstack_obs_schema_version',
);

foreach ( $kloudstack_obs_options as $kloudstack_obs_option ) {
    delete_option( $kloudstack_obs_option );

    if ( is_multisite() ) {
        delete_site_option( $kloudstack_obs_option );
    }
}

delete_transient( 'kloudstack_obs_breaker' );
delete_transient( 'kloudstack_obs_diagnostics' );
