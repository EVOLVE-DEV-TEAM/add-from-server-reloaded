<?php
/**
 * Uninstall Add From Server Lite
 *
 * Removes all plugin data when the plugin is deleted.
 *
 * @package   AFSRReloaded
 * @copyright Copyright (c) 2025, eLearning evolve, https://elearningevolve.com
 * @license   GPL-3.0+
 * @since     5.0.0
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete plugin options.
 *
 * @since 5.0.0
 */
function afsrreloaded_delete_plugin_options() {
	delete_option( 'afsrreloaded_root_directory' );
	delete_option( 'afsrreloaded_db_version' );
	delete_option( 'frmsvr_root' );
	// Legacy Multisite network setting (feature removed).
	if ( function_exists( 'delete_site_option' ) ) {
		delete_site_option( 'afsrreloaded_network_settings' );
	}
}

/**
 * Drop custom database tables.
 *
 * @since 5.3.0
 */
function afsrreloaded_drop_tables() {
	global $wpdb;

	$jobs_table  = $wpdb->prefix . 'afsrreloaded_jobs';
	$items_table = $wpdb->prefix . 'afsrreloaded_job_items';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $items_table ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $jobs_table ) );
}

/**
 * Clear scheduled cron events.
 *
 * @since 5.3.0
 */
function afsrreloaded_clear_cron() {
	$timestamp = wp_next_scheduled( 'afsrreloaded_process_import_jobs' );
	while ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'afsrreloaded_process_import_jobs' );
		$timestamp = wp_next_scheduled( 'afsrreloaded_process_import_jobs' );
	}

	wp_clear_scheduled_hook( 'afsrreloaded_process_import_jobs_soon' );
}

/**
 * Clean up on uninstall.
 *
 * @since 5.0.0
 */
function afsrreloaded_uninstall() {
	if ( ! current_user_can( 'delete_plugins' ) ) {
		return;
	}

	afsrreloaded_delete_plugin_options();
	afsrreloaded_drop_tables();
	afsrreloaded_clear_cron();

	// Imported media files are intentionally kept.
}

afsrreloaded_uninstall();
