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
delete_option( 'olimc_version' );
delete_option( 'olimc_scan_results' );
delete_option( 'olimc_scan_used_ids' );
delete_option( 'olimc_scan_date' );
delete_option( 'olimc_whitelist' );
delete_option( 'olimc_cron_enabled' );
delete_option( 'olimc_cron_frequency' );

// Clear scheduled events
wp_clear_scheduled_hook( 'olimc_scheduled_cleanup' );
