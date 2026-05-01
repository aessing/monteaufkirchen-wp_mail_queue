<?php
/**
 * Plugin Name: WP Mail Queue Throttle
 * Plugin URI: https://example.com/wp-mail-queue-throttle
 * Description: Queues WordPress mail for throttled replay through the configured wp_mail transport.
 * Version: 0.1.0
 * Author: WP Mail Queue
 * License: GPL-2.0-or-later
 * Text Domain: wp-mail-queue-throttle
 *
 * @package WP_Mail_Queue_Throttle
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WMQT_VERSION', '0.1.0' );
define( 'WMQT_PLUGIN_FILE', __FILE__ );
define( 'WMQT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WMQT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WMQT_OPTION_NAME', 'wmqt_settings' );
define( 'WMQT_CRON_SCHEDULE', 'wmqt_two_minutes' );
define( 'WMQT_CRON_HOOK', 'wmqt_process_queue' );

require_once WMQT_PLUGIN_DIR . 'includes/class-wp-mail-queue-settings.php';
require_once WMQT_PLUGIN_DIR . 'includes/class-wp-mail-queue-installer.php';
require_once WMQT_PLUGIN_DIR . 'includes/class-wp-mail-queue-plugin.php';

/**
 * Creates or updates plugin storage and schedules queue processing.
 */
function wmqt_activate() {
	$settings  = new WP_Mail_Queue_Settings();
	$installer = new WP_Mail_Queue_Installer( $settings );

	$installer->activate();
}
register_activation_hook( __FILE__, 'wmqt_activate' );

/**
 * Clears scheduled queue processing.
 */
function wmqt_deactivate() {
	$settings  = new WP_Mail_Queue_Settings();
	$installer = new WP_Mail_Queue_Installer( $settings );

	$installer->deactivate();
}
register_deactivation_hook( __FILE__, 'wmqt_deactivate' );

/**
 * Boots the plugin after WordPress has loaded active plugins.
 */
function wmqt_bootstrap() {
	$plugin = new WP_Mail_Queue_Plugin();
	$plugin->init();
}
add_action( 'plugins_loaded', 'wmqt_bootstrap' );
