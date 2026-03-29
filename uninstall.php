<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Oli_Media_Cleaner
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove all plugin options
delete_option( 'omc_version' );
delete_option( 'omc_scan_results' );
delete_option( 'omc_scan_used_ids' );
delete_option( 'omc_scan_date' );
delete_option( 'omc_whitelist' );
delete_option( 'omc_cron_enabled' );
delete_option( 'omc_cron_frequency' );

// Clear scheduled events
wp_clear_scheduled_hook( 'omc_scheduled_cleanup' );
