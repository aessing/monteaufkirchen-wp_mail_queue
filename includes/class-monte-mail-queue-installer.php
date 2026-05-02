<?php
/**
 * Installer and lifecycle hooks for Monte Mail Queue Throttle.
 *
 * @package Monte_Mail_Queue_Throttle
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates database tables and manages cron scheduling.
 */
class Monte_Mail_Queue_Installer {
	/**
	 * Settings dependency.
	 *
	 * @var Monte_Mail_Queue_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Monte_Mail_Queue_Settings $settings Settings dependency.
	 */
	public function __construct( Monte_Mail_Queue_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Handles plugin activation.
	 *
	 * @return void
	 */
	public function activate() {
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );

		$this->create_tables();
		$this->ensure_default_settings();
		update_option( 'wmqt_db_version', WMQT_VERSION );
		$this->schedule_event();
	}

	/**
	 * Updates database schema when a newer plugin version is loaded.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		if ( get_option( 'wmqt_db_version' ) === WMQT_VERSION ) {
			return;
		}

		$this->create_tables();
		update_option( 'wmqt_db_version', WMQT_VERSION );
	}

	/**
	 * Handles plugin deactivation.
	 *
	 * @return void
	 */
	public function deactivate() {
		wp_clear_scheduled_hook( WMQT_CRON_HOOK );
	}

	/**
	 * Registers the custom cron schedule.
	 *
	 * @param array<string, array<string, mixed>> $schedules Existing schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_cron_schedule( $schedules ) {
		if ( ! isset( $schedules[ WMQT_CRON_SCHEDULE ] ) ) {
			$schedules[ WMQT_CRON_SCHEDULE ] = array(
				'interval' => 120,
				'display'  => __( 'Every two minutes', 'monte-mail-queue-throttle' ),
			);
		}

		return $schedules;
	}

	/**
	 * Creates or updates plugin tables.
	 *
	 * @return void
	 */
	private function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$queue_table     = $wpdb->prefix . 'wmqt_queue';
		$logs_table      = $wpdb->prefix . 'wmqt_logs';

		$queue_sql = "CREATE TABLE {$queue_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			recipients longtext NOT NULL,
			subject text NOT NULL,
			message longtext NOT NULL,
			headers longtext NULL,
			attachments longtext NULL,
			source_plugin varchar(191) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'queued',
			attempts int(10) unsigned NOT NULL DEFAULT 0,
			max_attempts int(10) unsigned NOT NULL DEFAULT 3,
			last_error text NULL,
			queued_at datetime NOT NULL,
			next_attempt_at datetime NULL,
			updated_at datetime NOT NULL,
			sent_at datetime NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY status_next_attempt (status, next_attempt_at, id),
			KEY status_updated (status, updated_at),
			KEY source_plugin (source_plugin),
			KEY queued_at (queued_at)
		) {$charset_collate};";

		$logs_sql = "CREATE TABLE {$logs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			queue_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_type varchar(50) NOT NULL,
			message text NOT NULL,
			source_plugin varchar(191) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY queue_id (queue_id),
			KEY event_type (event_type),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $queue_sql );
		dbDelta( $logs_sql );
	}

	/**
	 * Persists defaults on first activation.
	 *
	 * @return void
	 */
	private function ensure_default_settings() {
		if ( false === get_option( WMQT_OPTION_NAME, false ) ) {
			add_option( WMQT_OPTION_NAME, $this->settings->defaults() );
		}
	}

	/**
	 * Schedules queue processing when absent.
	 *
	 * @return void
	 */
	private function schedule_event() {
		if ( ! wp_next_scheduled( WMQT_CRON_HOOK ) ) {
			wp_schedule_event( time() + 120, WMQT_CRON_SCHEDULE, WMQT_CRON_HOOK );
		}
	}
}
