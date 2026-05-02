<?php
/**
 * Uninstall cleanup for WP Mail Queue Throttle.
 *
 * @package WP_Mail_Queue_Throttle
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'wmqt_settings' );
wp_clear_scheduled_hook( 'wmqt_process_queue' );

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wmqt_queue" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wmqt_logs" );
