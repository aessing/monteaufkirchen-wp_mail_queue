<?php
require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'WMQT_CRON_SCHEDULE' ) ) {
	define( 'WMQT_CRON_SCHEDULE', 'wmqt_two_minutes' );
}

if ( ! defined( 'WMQT_CRON_HOOK' ) ) {
	define( 'WMQT_CRON_HOOK', 'wmqt_process_queue' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'WMQT_VERSION' ) ) {
	define( 'WMQT_VERSION', '0.4.1-test' );
}

if ( ! defined( 'WMQT_PLUGIN_URL' ) ) {
	define( 'WMQT_PLUGIN_URL', 'https://example.test/plugin/' );
}

$wmqt_test_cron = array(
	'scheduled' => array(),
	'cleared'   => array(),
);
$wmqt_test_messages = array();

function __( $text ) {
	return $text;
}

function _n( $single, $plural, $number ) {
	return 1 === (int) $number ? $single : $plural;
}

function esc_html__( $text ) {
	return $text;
}

function esc_attr__( $text ) {
	return $text;
}

function esc_html( $text ) {
	return (string) $text;
}

function esc_attr( $text ) {
	return (string) $text;
}

function esc_textarea( $text ) {
	return (string) $text;
}

function checked( $checked, $current, $display = false ) {
	unset( $display );
	return (string) $checked === (string) $current ? 'checked="checked"' : '';
}

function selected( $selected, $current, $display = false ) {
	unset( $display );
	return (string) $selected === (string) $current ? 'selected="selected"' : '';
}

function current_user_can( $capability ) {
	unset( $capability );
	return true;
}

function check_admin_referer( $action, $name ) {
	unset( $action, $name );
	return true;
}

function wp_unslash( $value ) {
	return $value;
}

function add_settings_error( $setting, $code, $message, $type ) {
	global $wmqt_test_messages;
	$wmqt_test_messages[] = array(
		'setting' => $setting,
		'code'    => $code,
		'message' => $message,
		'type'    => $type,
	);
}

function settings_errors( $setting ) {
	unset( $setting );
}

function wp_nonce_field( $action, $name ) {
	echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $action ) . '">';
}

function submit_button( $text, $type = 'primary', $name = 'submit', $wrap = true ) {
	unset( $type, $name, $wrap );
	echo '<button>' . esc_html( $text ) . '</button>';
}

function admin_url( $path = '' ) {
	return 'admin.php' . $path;
}

function esc_url( $url ) {
	return (string) $url;
}

function wp_die( $message ) {
	throw new Exception( 'wp_die: ' . $message );
}

function wp_enqueue_style( $handle, $src, $deps = array(), $ver = false ) {
	unset( $handle, $src, $deps, $ver );
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	unset( $hook, $callback, $priority, $accepted_args );
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	unset( $hook, $callback, $priority, $accepted_args );
}

function add_menu_page() {
	return 'menu-hook';
}

function add_submenu_page() {
	static $index = 0;
	$index++;
	return 'submenu-hook-' . $index;
}

function wp_next_scheduled( $hook ) {
	global $wmqt_test_cron;
	return isset( $wmqt_test_cron['scheduled'][ $hook ] ) ? $wmqt_test_cron['scheduled'][ $hook ]['timestamp'] : false;
}

function wp_schedule_event( $timestamp, $schedule, $hook ) {
	global $wmqt_test_cron;
	$wmqt_test_cron['scheduled'][ $hook ] = array(
		'timestamp' => (int) $timestamp,
		'schedule'  => $schedule,
	);
	return true;
}

function wp_clear_scheduled_hook( $hook ) {
	global $wmqt_test_cron;
	$wmqt_test_cron['cleared'][] = $hook;
	unset( $wmqt_test_cron['scheduled'][ $hook ] );
}

class Monte_Mail_Queue_Repository {
}

require_once __DIR__ . '/../includes/class-monte-mail-queue-settings.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-installer.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-admin.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-plugin.php';

class WMQT_Test_Installer extends Monte_Mail_Queue_Installer {
	public $reschedule_calls = 0;

	public function reschedule_event() {
		$this->reschedule_calls++;
	}
}

function wmqt_reset_test_runtime() {
	global $wmqt_test_cron;
	global $wmqt_test_messages;
	global $wmqt_test_options;

	$wmqt_test_options  = array();
	$wmqt_test_cron     = array(
		'scheduled' => array(),
		'cleared'   => array(),
	);
	$wmqt_test_messages = array();
	$_POST              = array();
}

wmqt_test( 'settings expose new throttle and azure defaults', function () {
	wmqt_reset_test_runtime();

	$settings = new Monte_Mail_Queue_Settings();
	$all      = $settings->get_all();

	wmqt_assert_same( 25, $all['rate_per_minute'], 'minute default' );
	wmqt_assert_same( 1500, $all['rate_per_hour'], 'hour default' );
	wmqt_assert_same( 2, $all['worker_interval_minutes'], 'worker interval default' );
	wmqt_assert_same( 0, $all['azure_email_enabled'], 'azure disabled default' );
	wmqt_assert_same( '', $all['azure_connection_string'], 'connection string default' );
	wmqt_assert_same( '', $all['azure_sender_domains'], 'sender domains default' );
	wmqt_assert_same( 'DoNotReply', $all['azure_sender_username'], 'sender username default' );
} );

wmqt_test( 'settings sanitize worker cadence and azure fields', function () {
	wmqt_reset_test_runtime();

	$settings = new Monte_Mail_Queue_Settings();
	$settings->update(
		array(
			'rate_per_hour'           => '0',
			'worker_interval_minutes' => '90',
			'azure_email_enabled'     => '1',
			'azure_connection_string' => " endpoint=https://example.communication.azure.com/;accesskey=secret \n",
			'azure_sender_domains'    => "mailing.example.com\n MAILING.example.com, bad space.test ",
			'azure_sender_username'   => ' Do Not Reply ',
			'azure_default_domain'    => ' MAILING.example.com ',
			'azure_reply_to'          => ' Reply@Example.com ',
		)
	);

	$all = $settings->get_all();

	wmqt_assert_same( 1, $all['rate_per_hour'], 'hour minimum' );
	wmqt_assert_same( 60, $all['worker_interval_minutes'], 'worker interval maximum' );
	wmqt_assert_same( 1, $all['azure_email_enabled'], 'azure checkbox' );
	wmqt_assert_same( 'endpoint=https://example.communication.azure.com/;accesskey=secret', $all['azure_connection_string'], 'connection string trim' );
	wmqt_assert_same( 'mailing.example.com,badspace.test', $all['azure_sender_domains'], 'domain cleanup' );
	wmqt_assert_same( 'DoNotReply', $all['azure_sender_username'], 'username cleanup' );
	wmqt_assert_same( 'mailing.example.com', $all['azure_default_domain'], 'default domain cleanup' );
	wmqt_assert_same( 'Reply@Example.com', $all['azure_reply_to'], 'reply-to cleanup' );
} );

wmqt_test( 'installer cron schedule uses configured worker interval', function () {
	wmqt_reset_test_runtime();

	$settings  = new Monte_Mail_Queue_Settings();
	$settings->update(
		array(
			'worker_interval_minutes' => 7,
		)
	);
	$installer = new Monte_Mail_Queue_Installer( $settings );
	$schedules = $installer->add_cron_schedule( array() );

	wmqt_assert_same( 420, $schedules[ WMQT_CRON_SCHEDULE ]['interval'], 'schedule seconds' );
	wmqt_assert_same( 'Every 7 minute(s)', $schedules[ WMQT_CRON_SCHEDULE ]['display'], 'schedule label' );
} );

wmqt_test( 'installer reschedule_event clears existing hook and schedules with configured cadence', function () {
	wmqt_reset_test_runtime();

	global $wmqt_test_cron;

	$settings  = new Monte_Mail_Queue_Settings();
	$settings->update(
		array(
			'worker_interval_minutes' => 3,
		)
	);
	$installer = new Monte_Mail_Queue_Installer( $settings );

	$wmqt_test_cron['scheduled'][ WMQT_CRON_HOOK ] = array(
		'timestamp' => 111,
		'schedule'  => WMQT_CRON_SCHEDULE,
	);

	$before = time();
	$installer->reschedule_event();
	$after = time();

	wmqt_assert_same( array( WMQT_CRON_HOOK ), $wmqt_test_cron['cleared'], 'cleared hook' );
	wmqt_assert_same( WMQT_CRON_SCHEDULE, $wmqt_test_cron['scheduled'][ WMQT_CRON_HOOK ]['schedule'], 'rescheduled cadence key' );
	wmqt_assert_true( $wmqt_test_cron['scheduled'][ WMQT_CRON_HOOK ]['timestamp'] >= $before + 180, 'rescheduled timestamp lower bound' );
	wmqt_assert_true( $wmqt_test_cron['scheduled'][ WMQT_CRON_HOOK ]['timestamp'] <= $after + 180, 'rescheduled timestamp upper bound' );
} );

wmqt_test( 'admin save reschedules only when worker interval changes', function () {
	wmqt_reset_test_runtime();

	$settings  = new Monte_Mail_Queue_Settings();
	$settings->update(
		array(
			'worker_interval_minutes' => 2,
		)
	);
	$installer = new WMQT_Test_Installer( $settings );
	$admin     = new Monte_Mail_Queue_Admin( $settings, new Monte_Mail_Queue_Repository(), $installer );

	$_POST = array(
		'wmqt_settings_nonce'      => 'nonce',
		'rate_per_minute'          => '25',
		'rate_per_hour'            => '1400',
		'worker_interval_minutes'  => '5',
		'max_attempts'             => '3',
		'queue_mode'               => 'all',
		'allowed_plugins'          => 'email-users',
		'log_retention_days'       => '30',
		'queue_retention_days'     => '180',
		'azure_email_enabled'      => '1',
		'azure_connection_string'  => ' endpoint=abc ',
		'azure_sender_domains'     => "one.example.com\ntwo.example.com",
		'azure_sender_username'    => ' Sender ',
		'azure_default_domain'     => 'one.example.com',
		'azure_reply_to'           => 'reply@example.com',
	);

	$save_method = new ReflectionMethod( 'Monte_Mail_Queue_Admin', 'save_settings' );
	$save_method->invoke( $admin );

	$all = $settings->get_all();

	wmqt_assert_same( 1, $installer->reschedule_calls, 'rescheduled once' );
	wmqt_assert_same( 1400, $all['rate_per_hour'], 'saved hourly rate' );
	wmqt_assert_same( 5, $all['worker_interval_minutes'], 'saved worker interval' );
	wmqt_assert_same( 1, $all['azure_email_enabled'], 'saved azure enabled' );
	wmqt_assert_same( 'one.example.com,two.example.com', $all['azure_sender_domains'], 'saved sender domains' );
} );

wmqt_test( 'admin settings page renders new cadence and azure fields', function () {
	wmqt_reset_test_runtime();

	$settings  = new Monte_Mail_Queue_Settings();
	$installer = new WMQT_Test_Installer( $settings );
	$admin     = new Monte_Mail_Queue_Admin( $settings, new Monte_Mail_Queue_Repository(), $installer );

	ob_start();
	$admin->render_settings();
	$output = ob_get_clean();

	wmqt_assert_true( false !== strpos( $output, 'name="rate_per_hour"' ), 'hourly field rendered' );
	wmqt_assert_true( false !== strpos( $output, 'name="worker_interval_minutes"' ), 'worker interval field rendered' );
	wmqt_assert_true( false !== strpos( $output, 'name="azure_email_enabled"' ), 'azure enabled field rendered' );
	wmqt_assert_true( false !== strpos( $output, 'name="azure_connection_string"' ), 'azure connection field rendered' );
	wmqt_assert_true( false !== strpos( $output, 'name="azure_sender_domains"' ), 'azure domains field rendered' );
	wmqt_assert_true( false !== strpos( $output, 'name="azure_sender_username"' ), 'azure username field rendered' );
	wmqt_assert_true( false !== strpos( $output, 'name="azure_default_domain"' ), 'azure default domain field rendered' );
	wmqt_assert_true( false !== strpos( $output, 'name="azure_reply_to"' ), 'azure reply-to field rendered' );
} );

wmqt_test( 'plugin admin uses shared installer instance', function () {
	wmqt_reset_test_runtime();

	if ( ! class_exists( 'Monte_Mail_Queue_Source_Detector' ) ) {
		class Monte_Mail_Queue_Source_Detector {
		}
	}

	if ( ! class_exists( 'Monte_Mail_Queue_Interceptor' ) ) {
		class Monte_Mail_Queue_Interceptor {
			public function __construct( $settings, $repository, $source_detector ) {
				unset( $settings, $repository, $source_detector );
			}
		}
	}

	if ( ! class_exists( 'Monte_Mail_Queue_Worker' ) ) {
		class Monte_Mail_Queue_Worker {
			public function __construct( $settings, $repository, $interceptor ) {
				unset( $settings, $repository, $interceptor );
			}
		}
	}

	$plugin = new Monte_Mail_Queue_Plugin();
	$admin  = $plugin->admin();

	$admin_installer = new ReflectionProperty( 'Monte_Mail_Queue_Admin', 'installer' );

	wmqt_assert_true( $admin_installer->getValue( $admin ) === $plugin->installer(), 'admin uses plugin installer' );
} );
