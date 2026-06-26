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
$wmqt_test_sent_mail = array();
$wmqt_test_wp_mail_result = true;

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

if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
		global $wmqt_test_sent_mail, $wmqt_test_wp_mail_result;

		$wmqt_test_sent_mail[] = array(
			'to'          => $to,
			'subject'     => $subject,
			'message'     => $message,
			'headers'     => $headers,
			'attachments' => $attachments,
		);

		return $wmqt_test_wp_mail_result;
	}
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

require_once __DIR__ . '/../includes/class-monte-mail-queue-settings.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-installer.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-repository.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-admin.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-plugin.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-delivery-result.php';

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
	global $wmqt_test_sent_mail;
	global $wmqt_test_wp_mail_result;

	$wmqt_test_options  = array();
	$wmqt_test_cron     = array(
		'scheduled' => array(),
		'cleared'   => array(),
	);
	$wmqt_test_messages = array();
	$wmqt_test_sent_mail = array();
	$wmqt_test_wp_mail_result = true;
	$_POST              = array();
	$_FILES             = array();
	$_GET               = array();
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
	$admin     = new Monte_Mail_Queue_Admin( $settings, new Monte_Mail_Queue_Repository( $settings ), $installer );

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
	$settings->update(
		array(
			'azure_sender_domains'  => "one.example.com\ntwo.example.com",
			'azure_sender_username' => 'DoNotReply',
		)
	);
	$installer = new WMQT_Test_Installer( $settings );
	$admin     = new Monte_Mail_Queue_Admin( $settings, new Monte_Mail_Queue_Repository( $settings ), $installer );

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
	wmqt_assert_true( false !== strpos( $output, 'Azure Communication Email' ), 'azure section heading rendered' );
	wmqt_assert_true( false !== strpos( $output, 'Enable Azure Email transport' ), 'azure transport copy rendered' );
	wmqt_assert_true( false !== strpos( $output, 'Send test email' ), 'test mail panel heading rendered' );
	wmqt_assert_true( false !== strpos( $output, 'enctype="multipart/form-data"' ), 'test mail form supports attachments' );
	wmqt_assert_true( false !== strpos( $output, 'name="test_sender_domain"' ), 'test sender domain field rendered' );
	wmqt_assert_true( false !== strpos( $output, 'name="test_sender_username"' ), 'test sender username field rendered' );
	wmqt_assert_true( false !== strpos( $output, 'name="test_recipient"' ), 'test recipient field rendered' );
	wmqt_assert_true( false !== strpos( $output, 'name="test_subject"' ), 'test subject field rendered' );
	wmqt_assert_true( false !== strpos( $output, 'name="test_body"' ), 'test body field rendered' );
	wmqt_assert_true( false !== strpos( $output, 'name="test_attachment"' ), 'test attachment field rendered' );
	wmqt_assert_true( false !== strpos( $output, 'name="worker_interval_minutes"' ), 'worker interval field rendered' );
	wmqt_assert_true( false !== strpos( $output, 'max="60"' ), 'worker interval field capped at sixty minutes' );
	wmqt_assert_true( false !== strpos( $output, '<option value="one.example.com"' ), 'first sender domain option rendered' );
	wmqt_assert_true( false !== strpos( $output, '<option value="two.example.com"' ), 'second sender domain option rendered' );
} );

wmqt_test( 'admin dashboard shows cadence-based per-run limit and worker interval', function () {
	wmqt_reset_test_runtime();

	$settings = new Monte_Mail_Queue_Settings();
	$settings->update(
		array(
			'rate_per_minute'         => 4,
			'rate_per_hour'           => 1400,
			'worker_interval_minutes' => 7,
		)
	);

	$repository = new class( $settings ) extends Monte_Mail_Queue_Repository {
		public function counts(): array {
			return array(
				'queued'     => 2,
				'processing' => 1,
				'sent'       => 9,
				'failed'     => 0,
			);
		}

		public function daily_status_counts( int $days = 30 ): array {
			unset( $days );
			return array();
		}

		public function queue_items_count( string $status = 'active' ): int {
			unset( $status );
			return 0;
		}

		public function queue_items( string $status = 'active', int $limit = 100, int $offset = 0 ): array {
			unset( $status, $limit, $offset );
			return array();
		}
	};
	$throttle   = new class() {
		public function status( $transport ) {
			unset( $transport );
			return array(
				'allowed'      => true,
				'reason'       => '',
				'minute_used'  => 8,
				'hour_used'    => 80,
				'minute_limit' => 25,
				'hour_limit'   => 1400,
			);
		}
	};
	$admin      = new Monte_Mail_Queue_Admin( $settings, $repository, new WMQT_Test_Installer( $settings ), $throttle );

	ob_start();
	$admin->render_dashboard();
	$output = ob_get_clean();

	wmqt_assert_true( false !== strpos( $output, '28' ), 'per-run limit uses worker interval' );
	wmqt_assert_true( false !== strpos( $output, '7 minutes' ), 'worker interval card rendered' );
	wmqt_assert_true( false !== strpos( $output, 'Configured hour rate' ), 'configured hour rate card rendered' );
	wmqt_assert_true( false !== strpos( $output, '1400 mails/hour' ), 'configured hour rate value rendered' );
	wmqt_assert_true( false !== strpos( $output, 'Minute window' ), 'minute window card rendered' );
	wmqt_assert_true( false !== strpos( $output, '8 / 25' ), 'minute window usage rendered' );
	wmqt_assert_true( false !== strpos( $output, 'Hour window' ), 'hour window card rendered' );
	wmqt_assert_true( false !== strpos( $output, '80 / 1400' ), 'hour window usage rendered' );
	wmqt_assert_true( false !== strpos( $output, 'Active transport' ), 'active transport card rendered' );
	wmqt_assert_true( false !== strpos( $output, 'wp_mail' ), 'active transport value rendered' );
} );

wmqt_test( 'admin test mail uses azure transport overrides and records usage', function () {
	wmqt_reset_test_runtime();

	$settings = new Monte_Mail_Queue_Settings();
	$settings->update(
		array(
			'azure_email_enabled'   => 1,
			'azure_sender_domains'  => 'mailing.example.com, second.example.com',
			'azure_sender_username' => 'DoNotReply',
		)
	);

	$repository = new class( $settings ) extends Monte_Mail_Queue_Repository {
		public $logs = array();

		public function log( int $queue_id, string $event_type, string $message, string $source_plugin = '' ): void {
			$this->logs[] = array( $queue_id, $event_type, $message, $source_plugin );
		}
	};
	$throttle   = new class() {
		public $recorded = array();

		public function status( $transport ) {
			unset( $transport );
			return array(
				'allowed'      => true,
				'reason'       => '',
				'minute_used'  => 0,
				'hour_used'    => 0,
				'minute_limit' => 25,
				'hour_limit'   => 1500,
			);
		}

		public function record_accepted( $queue_id, $transport, $provider_message_id = '' ) {
			$this->recorded[] = array( $queue_id, $transport, $provider_message_id );
		}
	};
	$azure      = new class() {
		public $calls = array();

		public function send( array $mail, array $overrides = array() ) {
			$this->calls[] = array( 'mail' => $mail, 'overrides' => $overrides );
			return Monte_Mail_Queue_Delivery_Result::accepted_result( 'op-test', 202 );
		}
	};
	$admin      = new Monte_Mail_Queue_Admin( $settings, $repository, new WMQT_Test_Installer( $settings ), $throttle, $azure );

	$_POST = array(
		'wmqt_test_mail_nonce' => 'nonce',
		'test_sender_domain'   => 'mailing.example.com',
		'test_sender_username' => 'QueueTest',
		'test_recipient'       => 'recipient@example.com',
		'test_subject'         => 'Azure test',
		'test_body'            => 'Hello world via email.',
	);

	$method = new ReflectionMethod( 'Monte_Mail_Queue_Admin', 'send_test_mail' );
	$method->invoke( $admin );

	wmqt_assert_same( 'QueueTest', $azure->calls[0]['overrides']['sender_username'], 'azure sender username override' );
	wmqt_assert_same( 'mailing.example.com', $azure->calls[0]['overrides']['sender_domain'], 'azure sender domain override' );
	wmqt_assert_same( array( array( 0, 'azure_communication_email', 'op-test' ) ), $throttle->recorded, 'azure test mail recorded in throttle window' );
	wmqt_assert_same( 'test_sent', $repository->logs[0][1], 'azure test mail success logged' );
} );

wmqt_test( 'admin test mail rejects manipulated sender domain before sending', function () {
	wmqt_reset_test_runtime();

	$settings = new Monte_Mail_Queue_Settings();
	$settings->update(
		array(
			'azure_email_enabled'   => 1,
			'azure_sender_domains'  => 'mailing.example.com, second.example.com',
			'azure_default_domain'  => 'mailing.example.com',
			'azure_sender_username' => 'DoNotReply',
		)
	);

	$repository = new class( $settings ) extends Monte_Mail_Queue_Repository {
		public $logs = array();

		public function log( int $queue_id, string $event_type, string $message, string $source_plugin = '' ): void {
			$this->logs[] = array( $queue_id, $event_type, $message, $source_plugin );
		}
	};
	$azure      = new class() {
		public $calls = array();

		public function send( array $mail, array $overrides = array() ) {
			$this->calls[] = array( 'mail' => $mail, 'overrides' => $overrides );
			return Monte_Mail_Queue_Delivery_Result::accepted_result( 'op-test', 202 );
		}
	};
	$admin      = new Monte_Mail_Queue_Admin( $settings, $repository, new WMQT_Test_Installer( $settings ), null, $azure );

	$_POST = array(
		'wmqt_test_mail_nonce' => 'nonce',
		'test_sender_domain'   => 'attacker.example.net',
		'test_sender_username' => 'QueueTest',
		'test_recipient'       => 'recipient@example.com',
		'test_subject'         => 'Azure test',
		'test_body'            => 'Hello world via email.',
	);

	$method = new ReflectionMethod( 'Monte_Mail_Queue_Admin', 'send_test_mail' );
	$method->invoke( $admin );

	wmqt_assert_same( array(), $azure->calls, 'azure send skipped for invalid domain' );
	wmqt_assert_same( 'test_failed', $repository->logs[0][1], 'invalid domain logged as failed' );
	wmqt_assert_same( 0, $repository->logs[0][0], 'invalid domain uses queue id zero' );

	global $wmqt_test_messages;
	wmqt_assert_same( 'wmqt_test_mail_failed', $wmqt_test_messages[0]['code'], 'settings error added for invalid domain' );
} );

wmqt_test( 'admin test mail ignores manipulated attachment tmp path', function () {
	wmqt_reset_test_runtime();

	global $wmqt_test_sent_mail;

	$settings = new Monte_Mail_Queue_Settings();
	$repository = new class( $settings ) extends Monte_Mail_Queue_Repository {
		public $logs = array();

		public function log( int $queue_id, string $event_type, string $message, string $source_plugin = '' ): void {
			$this->logs[] = array( $queue_id, $event_type, $message, $source_plugin );
		}
	};
	$admin      = new Monte_Mail_Queue_Admin( $settings, $repository, new WMQT_Test_Installer( $settings ) );
	$tmp_path = tempnam( sys_get_temp_dir(), 'wmqt-attachment-' );

	file_put_contents( $tmp_path, 'not-an-upload' );

	$_POST = array(
		'wmqt_test_mail_nonce' => 'nonce',
		'test_recipient'       => 'recipient@example.com',
		'test_subject'         => 'Attachment test',
		'test_body'            => 'Hello world via email.',
	);
	$_FILES = array(
		'test_attachment' => array(
			'error'    => UPLOAD_ERR_OK,
			'tmp_name' => $tmp_path,
		),
	);

	$method = new ReflectionMethod( 'Monte_Mail_Queue_Admin', 'send_test_mail' );
	$method->invoke( $admin );

	wmqt_assert_same( array(), $wmqt_test_sent_mail[0]['attachments'], 'invalid upload path omitted from attachments' );
	wmqt_assert_true( file_exists( $tmp_path ), 'invalid upload path not deleted' );

	unlink( $tmp_path );
} );

wmqt_test( 'admin log filter allows new task six event types', function () {
	wmqt_reset_test_runtime();

	$admin = new Monte_Mail_Queue_Admin( new Monte_Mail_Queue_Settings(), new Monte_Mail_Queue_Repository( new Monte_Mail_Queue_Settings() ), new WMQT_Test_Installer( new Monte_Mail_Queue_Settings() ) );

	foreach ( array( 'throttled_minute', 'throttled_hour', 'azure_send_accepted', 'test_sent', 'test_retry', 'test_failed' ) as $event_type ) {
		$_GET = array( 'event_type' => $event_type );
		$method = new ReflectionMethod( 'Monte_Mail_Queue_Admin', 'requested_event_type' );
		wmqt_assert_same( $event_type, $method->invoke( $admin ), 'requested event type allowed for ' . $event_type );
	}
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
			public function __construct( $settings, $repository, $interceptor, $throttle_window = null, $azure_client = null ) {
				unset( $settings, $repository, $interceptor, $throttle_window, $azure_client );
			}
		}
	}

	$plugin = new Monte_Mail_Queue_Plugin();
	$admin  = $plugin->admin();

	$admin_installer = new ReflectionProperty( 'Monte_Mail_Queue_Admin', 'installer' );
	$admin_throttle  = new ReflectionProperty( 'Monte_Mail_Queue_Admin', 'throttle_window' );
	$admin_azure     = new ReflectionProperty( 'Monte_Mail_Queue_Admin', 'azure_client' );

	wmqt_assert_true( $admin_installer->getValue( $admin ) === $plugin->installer(), 'admin uses plugin installer' );
	wmqt_assert_true( $admin_throttle->getValue( $admin ) === $plugin->throttle_window(), 'admin uses plugin throttle window' );
	wmqt_assert_true( $admin_azure->getValue( $admin ) === $plugin->azure_client(), 'admin uses plugin azure client' );
} );
