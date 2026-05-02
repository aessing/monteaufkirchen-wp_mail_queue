<?php
/**
 * Plugin Name: Monte Mail Queue Throttle
 * Plugin URI: https://www.linkedin.com/in/aessing/
 * Description: Queues WordPress mail for throttled replay through the configured wp_mail transport.
 * Version: 0.3.0
 * Requires at least: 5.8
 * Requires PHP: 7.0
 * Author: Andre Essing
 * Author URI: https://www.linkedin.com/in/aessing/
 * License: GPL-2.0-or-later
 * Text Domain: monte-mail-queue-throttle
 *
 * @package Monte_Mail_Queue_Throttle
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WMQT_VERSION', '0.3.0' );
define( 'WMQT_PLUGIN_FILE', __FILE__ );
define( 'WMQT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WMQT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WMQT_OPTION_NAME', 'wmqt_settings' );
define( 'WMQT_CRON_SCHEDULE', 'wmqt_two_minutes' );
define( 'WMQT_CRON_HOOK', 'wmqt_process_queue' );

require_once WMQT_PLUGIN_DIR . 'includes/class-monte-mail-queue-settings.php';
require_once WMQT_PLUGIN_DIR . 'includes/class-monte-mail-queue-installer.php';
require_once WMQT_PLUGIN_DIR . 'includes/class-monte-mail-queue-repository.php';
require_once WMQT_PLUGIN_DIR . 'includes/class-monte-mail-queue-source-detector.php';
require_once WMQT_PLUGIN_DIR . 'includes/class-monte-mail-queue-interceptor.php';
require_once WMQT_PLUGIN_DIR . 'includes/class-monte-mail-queue-worker.php';
require_once WMQT_PLUGIN_DIR . 'includes/class-monte-mail-queue-admin.php';
require_once WMQT_PLUGIN_DIR . 'includes/class-monte-mail-queue-plugin.php';

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
