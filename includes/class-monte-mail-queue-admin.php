<?php
/**
 * Admin views for Monte Mail Queue Throttle.
 *
 * @package Monte_Mail_Queue_Throttle
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and handles plugin admin screens.
 */
class Monte_Mail_Queue_Admin {
	const MENU_SLUG            = 'monte-mail-queue';
	const PER_PAGE             = 50;
	const CAPABILITY           = 'edit_others_posts';
	const SETTINGS_CAPABILITY  = 'manage_options';

	/**
	 * Settings dependency.
	 *
	 * @var Monte_Mail_Queue_Settings
	 */
	private $settings;

	/**
	 * Repository dependency.
	 *
	 * @var Monte_Mail_Queue_Repository
	 */
	private $repository;

	/**
	 * Installer dependency.
	 *
	 * @var Monte_Mail_Queue_Installer
	 */
	private $installer;

	/**
	 * Throttle window dependency.
	 *
	 * @var mixed
	 */
	private $throttle_window;

	/**
	 * Azure client dependency.
	 *
	 * @var mixed
	 */
	private $azure_client;

	/**
	 * Registered page hooks.
	 *
	 * @var array<string, bool>
	 */
	private $page_hooks = array();

	/**
	 * Constructor.
	 *
	 * @param Monte_Mail_Queue_Settings   $settings Settings dependency.
	 * @param Monte_Mail_Queue_Repository $repository Repository dependency.
	 * @param Monte_Mail_Queue_Installer  $installer Installer dependency.
	 * @param mixed                       $throttle_window Throttle window dependency.
	 * @param mixed                       $azure_client Azure client dependency.
	 */
	public function __construct( Monte_Mail_Queue_Settings $settings, Monte_Mail_Queue_Repository $repository, Monte_Mail_Queue_Installer $installer, $throttle_window = null, $azure_client = null ) {
		$this->settings        = $settings;
		$this->repository      = $repository;
		$this->installer       = $installer;
		$this->throttle_window = $throttle_window;
		$this->azure_client    = $azure_client;
	}

	/**
	 * Registers admin hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers top-level and submenu admin pages.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->page_hooks[ add_menu_page(
			__( 'Mail Queue', 'monte-mail-queue-throttle' ),
			__( 'Mail Queue', 'monte-mail-queue-throttle' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-email-alt2',
			80
		) ] = true;

		$this->page_hooks[ add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'monte-mail-queue-throttle' ),
			__( 'Dashboard', 'monte-mail-queue-throttle' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' )
		) ] = true;

		$this->page_hooks[ add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'monte-mail-queue-throttle' ),
			__( 'Settings', 'monte-mail-queue-throttle' ),
			self::SETTINGS_CAPABILITY,
			'monte-mail-queue-settings',
			array( $this, 'render_settings' )
		) ] = true;

		$this->page_hooks[ add_submenu_page(
			self::MENU_SLUG,
			__( 'Queue', 'monte-mail-queue-throttle' ),
			__( 'Queue', 'monte-mail-queue-throttle' ),
			self::CAPABILITY,
			'monte-mail-queue-items',
			array( $this, 'render_queue' )
		) ] = true;

		$this->page_hooks[ add_submenu_page(
			self::MENU_SLUG,
			__( 'Logs', 'monte-mail-queue-throttle' ),
			__( 'Logs', 'monte-mail-queue-throttle' ),
			self::CAPABILITY,
			'monte-mail-queue-logs',
			array( $this, 'render_logs' )
		) ] = true;
	}

	/**
	 * Enqueues admin assets on plugin pages only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( empty( $this->page_hooks[ $hook_suffix ] ) ) {
			return;
		}

		wp_enqueue_style(
			'monte-mail-queue-admin',
			WMQT_PLUGIN_URL . 'assets/admin.css',
			array(),
			WMQT_VERSION
		);
	}

	/**
	 * Renders the dashboard page.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'monte-mail-queue-throttle' ) );
		}

		$counts        = $this->repository->counts();
		$settings      = $this->settings->get_all();
		$rate          = max( 1, absint( $settings['rate_per_minute'] ?? 25 ) );
		$hour_rate     = max( 1, absint( $settings['rate_per_hour'] ?? 1500 ) );
		$interval      = max( 1, min( 60, absint( $settings['worker_interval_minutes'] ?? 2 ) ) );
		$per_run_limit = $rate * $interval;
		$next_cron     = wp_next_scheduled( WMQT_CRON_HOOK );
		$chart_data    = $this->repository->daily_status_counts( 30 );
		$active_total  = $this->repository->queue_items_count( 'active' );
		$active_items  = $this->repository->queue_items( 'active', 10 );
		$transport     = 1 === (int) ( $settings['azure_email_enabled'] ?? 0 ) ? 'azure_communication_email' : 'wp_mail';
		$window_status = $this->throttle_window && method_exists( $this->throttle_window, 'status' ) ? $this->throttle_window->status( $transport ) : array();
		$cards         = array(
			array( __( 'Queued', 'monte-mail-queue-throttle' ), (int) ( $counts['queued'] ?? 0 ) ),
			array( __( 'Processing', 'monte-mail-queue-throttle' ), (int) ( $counts['processing'] ?? 0 ) ),
			array( __( 'Sent', 'monte-mail-queue-throttle' ), (int) ( $counts['sent'] ?? 0 ) ),
			array( __( 'Failed', 'monte-mail-queue-throttle' ), (int) ( $counts['failed'] ?? 0 ) ),
			array( __( 'Configured rate', 'monte-mail-queue-throttle' ), sprintf( _n( '%d mail/min', '%d mails/min', $rate, 'monte-mail-queue-throttle' ), $rate ) ),
			array( __( 'Configured hour rate', 'monte-mail-queue-throttle' ), sprintf( _n( '%d mail/hour', '%d mails/hour', $hour_rate, 'monte-mail-queue-throttle' ), $hour_rate ) ),
			array( __( 'Worker interval', 'monte-mail-queue-throttle' ), sprintf( _n( '%d minute', '%d minutes', $interval, 'monte-mail-queue-throttle' ), $interval ) ),
			array( __( 'Minute window', 'monte-mail-queue-throttle' ), sprintf( '%d / %d', (int) ( $window_status['minute_used'] ?? 0 ), max( 1, (int) ( $window_status['minute_limit'] ?? $rate ) ) ) ),
			array( __( 'Hour window', 'monte-mail-queue-throttle' ), sprintf( '%d / %d', (int) ( $window_status['hour_used'] ?? 0 ), max( 1, (int) ( $window_status['hour_limit'] ?? $hour_rate ) ) ) ),
			array( __( 'Active transport', 'monte-mail-queue-throttle' ), $transport ),
			array( __( 'Per-run limit', 'monte-mail-queue-throttle' ), $per_run_limit ),
			array( __( 'Next cron', 'monte-mail-queue-throttle' ), $this->format_timestamp( $next_cron ) ),
		);

		echo '<div class="wrap wmqt-admin">';
		echo '<h1>' . esc_html__( 'Mail Queue Dashboard', 'monte-mail-queue-throttle' ) . '</h1>';
		echo '<div class="wmqt-actions">';
		if ( current_user_can( self::SETTINGS_CAPABILITY ) ) {
			$this->render_admin_link( 'monte-mail-queue-settings', __( 'Settings', 'monte-mail-queue-throttle' ) );
		}
		$this->render_admin_link( 'monte-mail-queue-items', __( 'Queue', 'monte-mail-queue-throttle' ) );
		$this->render_admin_link( 'monte-mail-queue-logs', __( 'Logs', 'monte-mail-queue-throttle' ) );
		echo '</div>';
		echo '<div class="wmqt-card-grid">';

		foreach ( $cards as $card ) {
			echo '<div class="wmqt-card">';
			echo '<h2>' . esc_html( $card[0] ) . '</h2>';
			echo '<p>' . esc_html( (string) $card[1] ) . '</p>';
			echo '</div>';
		}

		echo '</div>';
		$this->render_volume_chart( $chart_data );
		$this->render_dashboard_queue_preview( $active_items, $active_total );
		echo '</div>';
	}

	/**
	 * Renders and saves the settings page.
	 *
	 * @return void
	 */
	public function render_settings() {
		if ( ! current_user_can( self::SETTINGS_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'monte-mail-queue-throttle' ) );
		}

		if ( isset( $_POST['wmqt_settings_nonce'] ) ) {
			$this->save_settings();
		}

		if ( isset( $_POST['wmqt_test_mail_nonce'] ) ) {
			$this->send_test_mail();
		}

		$settings = $this->settings->get_all();

		echo '<div class="wrap wmqt-admin">';
		echo '<h1>' . esc_html__( 'Mail Queue Settings', 'monte-mail-queue-throttle' ) . '</h1>';
		settings_errors( 'wmqt_messages' );
		echo '<form method="post" action="">';
		wp_nonce_field( 'wmqt_save_settings', 'wmqt_settings_nonce' );
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_number_field( 'rate_per_minute', __( 'Mails per minute', 'monte-mail-queue-throttle' ), $settings['rate_per_minute'] ?? 25 );
		$this->render_number_field( 'rate_per_hour', __( 'Mails per hour', 'monte-mail-queue-throttle' ), $settings['rate_per_hour'] ?? 1500 );
		$this->render_number_field( 'worker_interval_minutes', __( 'Worker interval minutes', 'monte-mail-queue-throttle' ), $settings['worker_interval_minutes'] ?? 2, __( 'Set to 1 when wp-cron.php is called every minute.', 'monte-mail-queue-throttle' ), 60 );
		$this->render_number_field( 'max_attempts', __( 'Max retries', 'monte-mail-queue-throttle' ), $settings['max_attempts'] ?? 3 );
		$this->render_queue_mode_field( (string) ( $settings['queue_mode'] ?? 'all' ) );
		$this->render_text_field( 'allowed_plugins', __( 'Allowed plugin slugs', 'monte-mail-queue-throttle' ), $settings['allowed_plugins'] ?? '' );
		$this->render_number_field( 'log_retention_days', __( 'Log retention days', 'monte-mail-queue-throttle' ), $settings['log_retention_days'] ?? 30, __( 'How long delivery event rows in the logs table are kept.', 'monte-mail-queue-throttle' ) );
		$this->render_number_field( 'queue_retention_days', __( 'Completed queue retention days', 'monte-mail-queue-throttle' ), $settings['queue_retention_days'] ?? 180, __( 'Sent mails are pruned after this many days. Failed mails are always kept at least 365 days for audit.', 'monte-mail-queue-throttle' ) );
		echo '</tbody></table>';
		echo '<div class="wmqt-settings-section">';
		echo '<h2>' . esc_html__( 'Azure Communication Email', 'monte-mail-queue-throttle' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_checkbox_field( 'azure_email_enabled', __( 'Enable Azure Email transport', 'monte-mail-queue-throttle' ), $settings['azure_email_enabled'] ?? 0, __( 'When enabled, the queue worker sends through Azure Communication Services Email instead of wp_mail().', 'monte-mail-queue-throttle' ) );
		$this->render_textarea_field( 'azure_connection_string', __( 'ACS connection string', 'monte-mail-queue-throttle' ), $settings['azure_connection_string'] ?? '', __( 'Paste the Azure Communication Services connection string.', 'monte-mail-queue-throttle' ) );
		$this->render_textarea_field( 'azure_sender_domains', __( 'Verified sender domains', 'monte-mail-queue-throttle' ), $settings['azure_sender_domains'] ?? '', __( 'Enter one verified domain per line or comma-separated.', 'monte-mail-queue-throttle' ) );
		$this->render_text_field( 'azure_sender_username', __( 'Default sender username', 'monte-mail-queue-throttle' ), $settings['azure_sender_username'] ?? 'DoNotReply' );
		$this->render_text_field( 'azure_default_domain', __( 'Default sender domain', 'monte-mail-queue-throttle' ), $settings['azure_default_domain'] ?? '' );
		$this->render_email_field( 'azure_reply_to', __( 'Reply-to email', 'monte-mail-queue-throttle' ), $settings['azure_reply_to'] ?? '' );
		echo '</tbody></table>';
		echo '</div>';
		submit_button( __( 'Save Settings', 'monte-mail-queue-throttle' ) );
		echo '</form>';
		echo '<div class="wmqt-test-mail">';
		echo '<h2>' . esc_html__( 'Send test email', 'monte-mail-queue-throttle' ) . '</h2>';
		echo '<form method="post" enctype="multipart/form-data" action="">';
		wp_nonce_field( 'wmqt_send_test_mail', 'wmqt_test_mail_nonce' );
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_sender_domain_select( $settings );
		$this->render_text_field( 'test_sender_username', __( 'Sender email username', 'monte-mail-queue-throttle' ), $settings['azure_sender_username'] ?? 'DoNotReply' );
		$this->render_email_field( 'test_recipient', __( 'Recipient email address', 'monte-mail-queue-throttle' ), '' );
		$this->render_text_field( 'test_subject', __( 'Subject', 'monte-mail-queue-throttle' ), __( 'Test Email', 'monte-mail-queue-throttle' ) );
		$this->render_textarea_field( 'test_body', __( 'Body', 'monte-mail-queue-throttle' ), __( 'Hello world via email.', 'monte-mail-queue-throttle' ) );
		echo '<tr><th scope="row"><label for="test_attachment">' . esc_html__( 'Attachment', 'monte-mail-queue-throttle' ) . '</label></th><td><input type="file" name="test_attachment" id="test_attachment"></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Send', 'monte-mail-queue-throttle' ), 'primary', 'wmqt_send_test_mail' );
		echo '</form>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Renders the queue table.
	 *
	 * @return void
	 */
	public function render_queue() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'monte-mail-queue-throttle' ) );
		}

		$status = $this->requested_queue_status();
		$paged  = $this->requested_page_number();
		$total  = $this->repository->queue_items_count( $status );
		$items  = $this->repository->queue_items( $status, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );

		echo '<div class="wrap wmqt-admin">';
		echo '<div class="wmqt-page-header">';
		echo '<div>';
		echo '<h1>' . esc_html__( 'Mail Queue', 'monte-mail-queue-throttle' ) . '</h1>';
		echo '<p>' . esc_html__( 'Active queued and processing messages. Sent and failed history lives in Logs.', 'monte-mail-queue-throttle' ) . '</p>';
		echo '</div>';
		echo '<span class="wmqt-count-pill">' . esc_html( sprintf( _n( '%d item', '%d items', $total, 'monte-mail-queue-throttle' ), $total ) ) . '</span>';
		echo '</div>';
		$this->render_queue_status_filter( $status );
		echo '<div class="wmqt-table-shell">';
		echo '<table class="widefat wmqt-table">';
		echo '<thead><tr>';
		$this->render_table_headers( array( 'ID', 'Recipients', 'Subject', 'Source plugin', 'Status', 'Attempts', 'Last error', 'Queued', 'Sent' ) );
		echo '</tr></thead><tbody>';

		if ( empty( $items ) ) {
			echo '<tr><td colspan="9"><div class="wmqt-empty">' . esc_html__( 'No active queue items found.', 'monte-mail-queue-throttle' ) . '</div></td></tr>';
		}

		foreach ( $items as $item ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) (int) ( $item['id'] ?? 0 ) ) . '</td>';
			echo '<td class="wmqt-recipients">' . esc_html( $this->format_recipients( $item['to'] ?? array() ) ) . '</td>';
			echo '<td class="wmqt-subject">' . esc_html( (string) ( $item['subject'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $this->fallback_text( (string) ( $item['source_plugin'] ?? '' ) ) ) . '</td>';
			echo '<td>' . $this->status_badge( (string) ( $item['status'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) (int) ( $item['attempts'] ?? 0 ) ) . '</td>';
			echo '<td class="wmqt-error">' . esc_html( $this->fallback_text( (string) ( $item['last_error'] ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( $this->fallback_text( (string) ( $item['queued_at'] ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( $this->fallback_text( (string) ( $item['sent_at'] ?? '' ) ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
		$pagination_args = 'active' === $status ? array() : array( 'status' => $status );
		$this->render_pagination( 'monte-mail-queue-items', $paged, $total, $pagination_args );
		echo '</div>';
	}

	/**
	 * Renders the logs table.
	 *
	 * @return void
	 */
	public function render_logs() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'monte-mail-queue-throttle' ) );
		}

		$event_type = $this->requested_event_type();
		$paged      = $this->requested_page_number();
		$total      = $this->repository->logs_count( $event_type );
		$logs       = $this->repository->logs( $event_type, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );

		echo '<div class="wrap wmqt-admin">';
		echo '<div class="wmqt-page-header">';
		echo '<div>';
		echo '<h1>' . esc_html__( 'Mail Queue Logs', 'monte-mail-queue-throttle' ) . '</h1>';
		echo '<p>' . esc_html__( 'Delivery events with the related message details.', 'monte-mail-queue-throttle' ) . '</p>';
		echo '</div>';
		echo '<span class="wmqt-count-pill">' . esc_html( sprintf( _n( '%d event', '%d events', $total, 'monte-mail-queue-throttle' ), $total ) ) . '</span>';
		echo '</div>';
		$this->render_log_filter( $event_type );
		echo '<div class="wmqt-table-shell">';
		echo '<table class="widefat wmqt-table">';
		echo '<thead><tr>';
		$this->render_table_headers( array( 'Timestamp', 'Event', 'Queue ID', 'Recipients', 'Subject', 'Source plugin', 'Status', 'Attempts', 'Last error', 'Queued', 'Sent', 'Message' ) );
		echo '</tr></thead><tbody>';

		if ( empty( $logs ) ) {
			echo '<tr><td colspan="12"><div class="wmqt-empty">' . esc_html__( 'No log entries found.', 'monte-mail-queue-throttle' ) . '</div></td></tr>';
		}

		foreach ( $logs as $log ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $log['created_at'] ?? '' ) ) . '</td>';
			echo '<td>' . $this->status_badge( (string) ( $log['event_type'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) (int) ( $log['queue_id'] ?? 0 ) ) . '</td>';
			echo '<td class="wmqt-recipients">' . esc_html( $this->format_recipients( $log['to'] ?? array() ) ) . '</td>';
			echo '<td class="wmqt-subject">' . esc_html( $this->fallback_text( (string) ( $log['subject'] ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( $this->fallback_text( (string) ( $log['source_plugin'] ?? '' ) ) ) . '</td>';
			echo '<td>' . $this->status_badge( (string) ( $log['queue_status'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) (int) ( $log['attempts'] ?? 0 ) ) . '</td>';
			echo '<td class="wmqt-error">' . esc_html( $this->fallback_text( (string) ( $log['last_error'] ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( $this->fallback_text( (string) ( $log['queued_at'] ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( $this->fallback_text( (string) ( $log['sent_at'] ?? '' ) ) ) . '</td>';
			echo '<td class="wmqt-error">' . esc_html( (string) ( $log['message'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
		$this->render_pagination( 'monte-mail-queue-logs', $paged, $total, array( 'event_type' => $event_type ) );
		echo '</div>';
	}

	/**
	 * Saves submitted settings.
	 *
	 * @return void
	 */
	private function save_settings() {
		check_admin_referer( 'wmqt_save_settings', 'wmqt_settings_nonce' );

		if ( ! current_user_can( self::SETTINGS_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to save these settings.', 'monte-mail-queue-throttle' ) );
		}

		$previous = $this->settings->get_all();

		$this->settings->update(
			array(
				'rate_per_minute'      => isset( $_POST['rate_per_minute'] ) ? wp_unslash( $_POST['rate_per_minute'] ) : 25,
				'rate_per_hour'        => isset( $_POST['rate_per_hour'] ) ? wp_unslash( $_POST['rate_per_hour'] ) : 1500,
				'worker_interval_minutes' => isset( $_POST['worker_interval_minutes'] ) ? wp_unslash( $_POST['worker_interval_minutes'] ) : 2,
				'max_attempts'         => isset( $_POST['max_attempts'] ) ? wp_unslash( $_POST['max_attempts'] ) : 3,
				'queue_mode'           => isset( $_POST['queue_mode'] ) ? wp_unslash( $_POST['queue_mode'] ) : 'all',
				'allowed_plugins'      => isset( $_POST['allowed_plugins'] ) ? wp_unslash( $_POST['allowed_plugins'] ) : '',
				'log_retention_days'   => isset( $_POST['log_retention_days'] ) ? wp_unslash( $_POST['log_retention_days'] ) : 30,
				'queue_retention_days' => isset( $_POST['queue_retention_days'] ) ? wp_unslash( $_POST['queue_retention_days'] ) : 180,
				'azure_email_enabled'  => isset( $_POST['azure_email_enabled'] ) ? wp_unslash( $_POST['azure_email_enabled'] ) : 0,
				'azure_connection_string' => isset( $_POST['azure_connection_string'] ) ? wp_unslash( $_POST['azure_connection_string'] ) : '',
				'azure_sender_domains' => isset( $_POST['azure_sender_domains'] ) ? wp_unslash( $_POST['azure_sender_domains'] ) : '',
				'azure_sender_username' => isset( $_POST['azure_sender_username'] ) ? wp_unslash( $_POST['azure_sender_username'] ) : 'DoNotReply',
				'azure_default_domain' => isset( $_POST['azure_default_domain'] ) ? wp_unslash( $_POST['azure_default_domain'] ) : '',
				'azure_reply_to'       => isset( $_POST['azure_reply_to'] ) ? wp_unslash( $_POST['azure_reply_to'] ) : '',
			)
		);

		$current = $this->settings->get_all();

		if ( (int) $previous['worker_interval_minutes'] !== (int) $current['worker_interval_minutes'] ) {
			$this->installer->reschedule_event();
		}

		add_settings_error(
			'wmqt_messages',
			'wmqt_settings_saved',
			__( 'Settings saved.', 'monte-mail-queue-throttle' ),
			'updated'
		);
	}

	/**
	 * Returns normalized sender domains from settings input.
	 *
	 * @param string $raw_domains Raw sender domains.
	 * @return array<int, string>
	 */
	private function sender_domains( $raw_domains ) {
		$domains = preg_split( '/[\r\n,]+/', (string) $raw_domains );
		$cleaned = array();

		if ( ! is_array( $domains ) ) {
			return $cleaned;
		}

		foreach ( $domains as $domain ) {
			$domain = sanitize_text_field( $domain );

			if ( '' === $domain ) {
				continue;
			}

			$cleaned[] = $domain;
		}

		return array_values( array_unique( $cleaned ) );
	}

	/**
	 * Builds headers for manual test sends.
	 *
	 * @param string $sender_username Sender username.
	 * @param string $sender_domain Sender domain.
	 * @param string $reply_to Reply-to address.
	 * @return array<int, string>
	 */
	private function test_mail_headers( $sender_username, $sender_domain, $reply_to ) {
		$headers = array();

		if ( '' !== $sender_username && '' !== $sender_domain ) {
			$headers[] = 'From: ' . $sender_username . '@' . $sender_domain;
		}

		if ( '' !== $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		return $headers;
	}

	/**
	 * Returns the uploaded temporary file path for the test attachment.
	 *
	 * @return string
	 */
	private function uploaded_test_attachment_path() {
		if ( empty( $_FILES['test_attachment']['tmp_name'] ) ) {
			return '';
		}

		$path = (string) $_FILES['test_attachment']['tmp_name'];

		return file_exists( $path ) ? $path : '';
	}

	/**
	 * Records an accepted manual test send.
	 *
	 * @param string $transport Transport slug.
	 * @param string $provider_message_id Provider message identifier.
	 * @return void
	 */
	private function record_test_mail_acceptance( $transport, $provider_message_id = '' ) {
		if ( $this->throttle_window && method_exists( $this->throttle_window, 'record_accepted' ) ) {
			$this->throttle_window->record_accepted( 0, $transport, $provider_message_id );
		}

		$this->repository->log( 0, 'test_sent', __( 'Test email accepted for delivery.', 'monte-mail-queue-throttle' ), '' );
		add_settings_error( 'wmqt_messages', 'wmqt_test_mail_sent', __( 'Test email accepted for delivery.', 'monte-mail-queue-throttle' ), 'updated' );
	}

	/**
	 * Formats a throttling message from the window status payload.
	 *
	 * @param array<string, mixed> $status Throttle window status.
	 * @return string
	 */
	private function throttle_message( array $status ) {
		if ( 'hour' === ( $status['reason'] ?? '' ) ) {
			return sprintf(
				'Hour window full: %d/%d used.',
				(int) ( $status['hour_used'] ?? 0 ),
				(int) ( $status['hour_limit'] ?? 0 )
			);
		}

		return sprintf(
			'Minute window full: %d/%d used.',
			(int) ( $status['minute_used'] ?? 0 ),
			(int) ( $status['minute_limit'] ?? 0 )
		);
	}

	/**
	 * Sends a manual test message from the admin screen.
	 *
	 * @return void
	 */
	private function send_test_mail() {
		check_admin_referer( 'wmqt_send_test_mail', 'wmqt_test_mail_nonce' );

		if ( ! current_user_can( self::SETTINGS_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to send test emails.', 'monte-mail-queue-throttle' ) );
		}

		$settings        = $this->settings->get_all();
		$transport       = 1 === (int) ( $settings['azure_email_enabled'] ?? 0 ) ? 'azure_communication_email' : 'wp_mail';
		$sender_domain   = isset( $_POST['test_sender_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['test_sender_domain'] ) ) : (string) ( $settings['azure_default_domain'] ?? '' );
		$sender_username = isset( $_POST['test_sender_username'] ) ? sanitize_text_field( wp_unslash( $_POST['test_sender_username'] ) ) : (string) ( $settings['azure_sender_username'] ?? 'DoNotReply' );
		$recipient       = isset( $_POST['test_recipient'] ) ? sanitize_email( wp_unslash( $_POST['test_recipient'] ) ) : '';
		$subject         = isset( $_POST['test_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['test_subject'] ) ) : __( 'Test Email', 'monte-mail-queue-throttle' );
		$body            = isset( $_POST['test_body'] ) ? (string) wp_unslash( $_POST['test_body'] ) : __( 'Hello world via email.', 'monte-mail-queue-throttle' );
		$attachment_path = $this->uploaded_test_attachment_path();
		$mail            = array(
			'to'          => array( $recipient ),
			'subject'     => $subject,
			'message'     => $body,
			'headers'     => $this->test_mail_headers( $sender_username, $sender_domain, (string) ( $settings['azure_reply_to'] ?? '' ) ),
			'attachments' => '' !== $attachment_path ? array( $attachment_path ) : array(),
		);
		$status          = $this->throttle_window && method_exists( $this->throttle_window, 'status' ) ? $this->throttle_window->status( $transport ) : array( 'allowed' => true );

		try {
			if ( empty( $status['allowed'] ) ) {
				$this->repository->log( 0, 'minute' === ( $status['reason'] ?? '' ) ? 'throttled_minute' : 'throttled_hour', $this->throttle_message( $status ), '' );
				add_settings_error( 'wmqt_messages', 'wmqt_test_mail_throttled', __( 'Test email was throttled by the active send window.', 'monte-mail-queue-throttle' ), 'error' );
				return;
			}

			if ( 'azure_communication_email' === $transport ) {
				$result = $this->azure_client && method_exists( $this->azure_client, 'send' ) ? $this->azure_client->send(
					$mail,
					array(
						'sender_username' => $sender_username,
						'sender_domain'   => $sender_domain,
					)
				) : Monte_Mail_Queue_Delivery_Result::retry_result( 'Azure email client is not configured.' );

				if ( $result->accepted() ) {
					$this->record_test_mail_acceptance( $transport, $result->provider_message_id() );
					return;
				}

				if ( $result->retryable() ) {
					$this->repository->log( 0, 'test_retry', $result->error(), '' );
					add_settings_error( 'wmqt_messages', 'wmqt_test_mail_retry', $result->error(), 'error' );
					return;
				}

				$this->repository->log( 0, 'test_failed', $result->error(), '' );
				add_settings_error( 'wmqt_messages', 'wmqt_test_mail_failed', $result->error(), 'error' );
				return;
			}

			if ( wp_mail( $recipient, $subject, $body, $mail['headers'], $mail['attachments'] ) ) {
				$this->record_test_mail_acceptance( $transport );
				return;
			}

			$this->repository->log( 0, 'test_failed', __( 'wp_mail() returned false for the test email.', 'monte-mail-queue-throttle' ), '' );
			add_settings_error( 'wmqt_messages', 'wmqt_test_mail_failed', __( 'wp_mail() returned false for the test email.', 'monte-mail-queue-throttle' ), 'error' );
		} finally {
			if ( '' !== $attachment_path && file_exists( $attachment_path ) ) {
				@unlink( $attachment_path );
			}
		}
	}

	/**
	 * Renders a dashboard admin link.
	 *
	 * @param string $slug Page slug.
	 * @param string $label Link label.
	 * @return void
	 */
	private function render_admin_link( $slug, $label ) {
		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . $slug ) ),
			esc_html( $label )
		);
	}

	/**
	 * Renders the 30-day stacked status chart.
	 *
	 * @param array<string, mixed> $chart_data Chart data from the repository.
	 * @return void
	 */
	private function render_volume_chart( array $chart_data ) {
		$days      = isset( $chart_data['days'] ) && is_array( $chart_data['days'] ) ? $chart_data['days'] : array();
		$max_total = max( 1, (int) ( $chart_data['max_total'] ?? 1 ) );
		$totals    = isset( $chart_data['totals'] ) && is_array( $chart_data['totals'] ) ? $chart_data['totals'] : array();
		$statuses  = array(
			'queued'     => __( 'Queued', 'monte-mail-queue-throttle' ),
			'processing' => __( 'Processing', 'monte-mail-queue-throttle' ),
			'failed'     => __( 'Failed', 'monte-mail-queue-throttle' ),
			'sent'       => __( 'Sent', 'monte-mail-queue-throttle' ),
		);

		echo '<section class="wmqt-chart-card">';
		echo '<div class="wmqt-chart-header">';
		echo '<div>';
		echo '<h2>' . esc_html__( 'Mail volume, last 30 days', 'monte-mail-queue-throttle' ) . '</h2>';
		echo '<p>' . esc_html__( 'Each mail is counted once by its current status. Queued and processing rows are bucketed by their queued date; sent rows by sent date; failed rows by failure date.', 'monte-mail-queue-throttle' ) . '</p>';
		echo '</div>';
		echo '<div class="wmqt-chart-legend">';

		foreach ( $statuses as $status => $label ) {
			$total = (int) ( $totals[ $status ] ?? 0 );
			printf(
				'<span class="wmqt-legend-item"><span class="wmqt-dot wmqt-dot-%1$s"></span>%2$s <strong>%3$d</strong></span>',
				esc_attr( $status ),
				esc_html( $label ),
				$total
			);
		}

		echo '</div>';
		echo '</div>';
		echo '<div class="wmqt-chart" role="img" aria-label="' . esc_attr__( 'Stacked daily mail volume chart for the last 30 days.', 'monte-mail-queue-throttle' ) . '">';
		echo '<div class="wmqt-y-axis"><span>' . esc_html( (string) $max_total ) . '</span><span>' . esc_html( (string) (int) round( $max_total * 0.66 ) ) . '</span><span>' . esc_html( (string) (int) round( $max_total * 0.33 ) ) . '</span><span>0</span></div>';
		echo '<div class="wmqt-plot">';

		foreach ( $days as $day ) {
			$label = (string) ( $day['label'] ?? '' );
			$total = (int) ( $day['total'] ?? 0 );

			echo '<div class="wmqt-day" title="' . esc_attr( sprintf( '%s: %d total', $label, $total ) ) . '">';

			foreach ( array( 'sent', 'queued', 'processing', 'failed' ) as $status ) {
				$count  = (int) ( $day[ $status ] ?? 0 );
				$height = 0 < $count ? max( 2, round( ( $count / $max_total ) * 100, 2 ) ) : 0;

				printf(
					'<span class="wmqt-series wmqt-series-%1$s" style="height:%2$s%%" title="%3$s"></span>',
					esc_attr( $status ),
					esc_attr( (string) $height ),
					esc_attr( sprintf( '%s %s: %d', $label, $statuses[ $status ], $count ) )
				);
			}

			echo '</div>';
		}

		echo '</div>';
		echo '<div class="wmqt-x-axis"><span>' . esc_html__( '30d ago', 'monte-mail-queue-throttle' ) . '</span><span>' . esc_html__( '24d', 'monte-mail-queue-throttle' ) . '</span><span>' . esc_html__( '18d', 'monte-mail-queue-throttle' ) . '</span><span>' . esc_html__( '12d', 'monte-mail-queue-throttle' ) . '</span><span>' . esc_html__( '6d', 'monte-mail-queue-throttle' ) . '</span><span>' . esc_html__( 'Today', 'monte-mail-queue-throttle' ) . '</span></div>';
		echo '</div>';
		echo '</section>';
	}

	/**
	 * Renders the dashboard active queue preview.
	 *
	 * @param array<int, array<string, mixed>> $items Active queue rows.
	 * @param int                             $total Total active rows.
	 * @return void
	 */
	private function render_dashboard_queue_preview( array $items, $total ) {
		echo '<section class="wmqt-preview">';
		echo '<div class="wmqt-page-header">';
		echo '<div>';
		echo '<h2>' . esc_html__( 'Active queue', 'monte-mail-queue-throttle' ) . '</h2>';
		echo '<p>' . esc_html__( 'The next messages waiting for throttled delivery.', 'monte-mail-queue-throttle' ) . '</p>';
		echo '</div>';
		echo '<span class="wmqt-count-pill">' . esc_html( sprintf( _n( '%d active item', '%d active items', (int) $total, 'monte-mail-queue-throttle' ), (int) $total ) ) . '</span>';
		echo '</div>';
		echo '<div class="wmqt-table-shell">';
		echo '<table class="widefat wmqt-table wmqt-table-preview">';
		echo '<thead><tr>';
		$this->render_table_headers( array( 'ID', 'Recipients', 'Subject', 'Source plugin', 'Status', 'Attempts', 'Queued', 'Last error' ) );
		echo '</tr></thead><tbody>';

		if ( empty( $items ) ) {
			echo '<tr><td colspan="8"><div class="wmqt-empty">' . esc_html__( 'No active queue items found.', 'monte-mail-queue-throttle' ) . '</div></td></tr>';
		}

		foreach ( $items as $item ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) (int) ( $item['id'] ?? 0 ) ) . '</td>';
			echo '<td class="wmqt-recipients">' . esc_html( $this->format_recipients( $item['to'] ?? array() ) ) . '</td>';
			echo '<td class="wmqt-subject">' . esc_html( (string) ( $item['subject'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $this->fallback_text( (string) ( $item['source_plugin'] ?? '' ) ) ) . '</td>';
			echo '<td>' . $this->status_badge( (string) ( $item['status'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) (int) ( $item['attempts'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( $this->fallback_text( (string) ( $item['queued_at'] ?? '' ) ) ) . '</td>';
			echo '<td class="wmqt-error">' . esc_html( $this->fallback_text( (string) ( $item['last_error'] ?? '' ) ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
		echo '<div class="wmqt-preview-footer">';
		$this->render_admin_link( 'monte-mail-queue-items', __( 'Open full queue', 'monte-mail-queue-throttle' ) );
		echo '</div>';
		echo '</section>';
	}

	/**
	 * Renders a positive integer settings field.
	 *
	 * @param string $name Field name.
	 * @param string $label Field label.
	 * @param mixed  $value Field value.
	 * @param string $description Optional help text shown below the field.
	 * @param int    $max Optional maximum value.
	 * @return void
	 */
	private function render_number_field( $name, $label, $value, $description = '', $max = 0 ) {
		$description_html = '' !== $description ? '<p class="description">' . esc_html( $description ) . '</p>' : '';
		$max_attr         = 0 < (int) $max ? sprintf( ' max="%d"', (int) $max ) : '';

		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input name="%1$s" id="%1$s" type="number" min="1"%5$s value="%3$s" class="small-text">%4$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( (string) max( 1, absint( $value ) ) ),
			$description_html,
			$max_attr
		);
	}

	/**
	 * Renders a text settings field.
	 *
	 * @param string $name Field name.
	 * @param string $label Field label.
	 * @param mixed  $value Field value.
	 * @return void
	 */
	private function render_text_field( $name, $label, $value, $description = '' ) {
		$description_html = '' !== $description ? '<p class="description">' . esc_html( $description ) . '</p>' : '';

		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input name="%1$s" id="%1$s" type="text" value="%3$s" class="regular-text">%4$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( (string) $value ),
			$description_html
		);
	}

	/**
	 * Renders a checkbox settings field.
	 *
	 * @param string $name Field name.
	 * @param string $label Field label.
	 * @param mixed  $value Field value.
	 * @param string $description Optional help text shown below the field.
	 * @return void
	 */
	private function render_checkbox_field( $name, $label, $value, $description = '' ) {
		$description_html = '' !== $description ? '<p class="description">' . esc_html( $description ) . '</p>' : '';

		printf(
			'<tr><th scope="row">%2$s</th><td><label><input name="%1$s" id="%1$s" type="checkbox" value="1" %3$s> %4$s</label>%5$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			checked( 1, (int) $value, false ),
			esc_html__( 'Enabled', 'monte-mail-queue-throttle' ),
			$description_html
		);
	}

	/**
	 * Renders a textarea settings field.
	 *
	 * @param string $name Field name.
	 * @param string $label Field label.
	 * @param mixed  $value Field value.
	 * @param string $description Optional help text shown below the field.
	 * @return void
	 */
	private function render_textarea_field( $name, $label, $value, $description = '' ) {
		$description_html = '' !== $description ? '<p class="description">' . esc_html( $description ) . '</p>' : '';

		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><textarea name="%1$s" id="%1$s" rows="4" class="large-text code">%3$s</textarea>%4$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_textarea( (string) $value ),
			$description_html
		);
	}

	/**
	 * Renders an email settings field.
	 *
	 * @param string $name Field name.
	 * @param string $label Field label.
	 * @param mixed  $value Field value.
	 * @param string $description Optional help text shown below the field.
	 * @return void
	 */
	private function render_email_field( $name, $label, $value, $description = '' ) {
		$description_html = '' !== $description ? '<p class="description">' . esc_html( $description ) . '</p>' : '';

		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input name="%1$s" id="%1$s" type="email" value="%3$s" class="regular-text">%4$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( (string) $value ),
			$description_html
		);
	}

	/**
	 * Renders the sender-domain selector for test emails.
	 *
	 * @param array<string, mixed> $settings Saved plugin settings.
	 * @return void
	 */
	private function render_sender_domain_select( array $settings ) {
		$domains  = $this->sender_domains( (string) ( $settings['azure_sender_domains'] ?? '' ) );
		$selected = (string) ( $settings['azure_default_domain'] ?? '' );

		if ( '' === $selected && ! empty( $domains ) ) {
			$selected = $domains[0];
		}

		echo '<tr><th scope="row"><label for="test_sender_domain">' . esc_html__( 'Sender email domain', 'monte-mail-queue-throttle' ) . '</label></th><td>';
		echo '<select name="test_sender_domain" id="test_sender_domain">';

		foreach ( $domains as $domain ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $domain ),
				selected( $domain, $selected, false ),
				esc_html( $domain )
			);
		}

		echo '</select></td></tr>';
	}

	/**
	 * Renders the queue mode radio field.
	 *
	 * @param string $selected Selected queue mode.
	 * @return void
	 */
	private function render_queue_mode_field( $selected ) {
		$selected = in_array( $selected, array( 'all', 'selected' ), true ) ? $selected : 'all';

		echo '<tr><th scope="row">' . esc_html__( 'Queue mode', 'monte-mail-queue-throttle' ) . '</th><td><fieldset>';
		printf(
			'<label><input type="radio" name="queue_mode" value="all" %s> %s</label><br>',
			checked( 'all', $selected, false ),
			esc_html__( 'All mails', 'monte-mail-queue-throttle' )
		);
		printf(
			'<label><input type="radio" name="queue_mode" value="selected" %s> %s</label>',
			checked( 'selected', $selected, false ),
			esc_html__( 'Selected plugin slugs', 'monte-mail-queue-throttle' )
		);
		echo '</fieldset></td></tr>';
	}

	/**
	 * Renders the queue status filter.
	 *
	 * @param string $selected Selected status.
	 * @return void
	 */
	private function render_queue_status_filter( $selected ) {
		$statuses = array(
			'active'     => __( 'Queued + processing', 'monte-mail-queue-throttle' ),
			'queued'     => __( 'Queued', 'monte-mail-queue-throttle' ),
			'processing' => __( 'Processing', 'monte-mail-queue-throttle' ),
		);

		echo '<form method="get" class="wmqt-filter">';
		echo '<input type="hidden" name="page" value="monte-mail-queue-items">';
		echo '<label for="wmqt-status-filter">' . esc_html__( 'Status', 'monte-mail-queue-throttle' ) . '</label> ';
		echo '<select id="wmqt-status-filter" name="status">';
		foreach ( $statuses as $status => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $status ),
				selected( $status, $selected, false ),
				esc_html( $label )
			);
		}
		echo '</select> ';
		submit_button( __( 'Filter', 'monte-mail-queue-throttle' ), 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * Renders the log event filter.
	 *
	 * @param string $selected Selected event type.
	 * @return void
	 */
	private function render_log_filter( $selected ) {
		$events = array(
			''               => __( 'All events', 'monte-mail-queue-throttle' ),
			'queued'         => __( 'Queued', 'monte-mail-queue-throttle' ),
			'sent'           => __( 'Sent', 'monte-mail-queue-throttle' ),
			'retry'          => __( 'Retry', 'monte-mail-queue-throttle' ),
			'failed'         => __( 'Failed', 'monte-mail-queue-throttle' ),
			'recovered'      => __( 'Recovered', 'monte-mail-queue-throttle' ),
			'encode_failed'  => __( 'Encode failed', 'monte-mail-queue-throttle' ),
			'enqueue_failed' => __( 'Enqueue failed', 'monte-mail-queue-throttle' ),
			'throttled_minute' => __( 'Throttled minute', 'monte-mail-queue-throttle' ),
			'throttled_hour' => __( 'Throttled hour', 'monte-mail-queue-throttle' ),
			'azure_send_accepted' => __( 'Azure send accepted', 'monte-mail-queue-throttle' ),
			'test_sent'      => __( 'Test sent', 'monte-mail-queue-throttle' ),
			'test_retry'     => __( 'Test retry', 'monte-mail-queue-throttle' ),
			'test_failed'    => __( 'Test failed', 'monte-mail-queue-throttle' ),
			'attachment_missing' => __( 'Attachment missing', 'monte-mail-queue-throttle' ),
		);

		echo '<form method="get" class="wmqt-filter">';
		echo '<input type="hidden" name="page" value="monte-mail-queue-logs">';
		echo '<label for="wmqt-event-filter">' . esc_html__( 'Event', 'monte-mail-queue-throttle' ) . '</label> ';
		echo '<select id="wmqt-event-filter" name="event_type">';
		foreach ( $events as $event => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $event ),
				selected( $event, $selected, false ),
				esc_html( $label )
			);
		}
		echo '</select> ';
		submit_button( __( 'Filter', 'monte-mail-queue-throttle' ), 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * Renders table headers.
	 *
	 * @param array<int, string> $headers Headers.
	 * @return void
	 */
	private function render_table_headers( array $headers ) {
		foreach ( $headers as $header ) {
			echo '<th scope="col">' . esc_html( $header ) . '</th>';
		}
	}

	/**
	 * Returns an allowlisted requested queue status.
	 *
	 * @return string
	 */
	private function requested_queue_status() {
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		return in_array( $status, array( 'active', 'queued', 'processing' ), true ) ? $status : 'active';
	}

	/**
	 * Returns an allowlisted requested log event type.
	 *
	 * @return string
	 */
	private function requested_event_type() {
		$event_type = isset( $_GET['event_type'] ) ? sanitize_key( wp_unslash( $_GET['event_type'] ) ) : '';

		return in_array( $event_type, array( 'queued', 'sent', 'retry', 'failed', 'recovered', 'encode_failed', 'enqueue_failed', 'throttled_minute', 'throttled_hour', 'azure_send_accepted', 'test_sent', 'test_retry', 'test_failed', 'attachment_missing' ), true ) ? $event_type : '';
	}

	/**
	 * Returns the requested admin page number.
	 *
	 * @return int
	 */
	private function requested_page_number() {
		return max( 1, absint( isset( $_GET['paged'] ) ? wp_unslash( $_GET['paged'] ) : 1 ) );
	}

	/**
	 * Formats recipients for table display.
	 *
	 * @param mixed $recipients Recipients.
	 * @return string
	 */
	private function format_recipients( $recipients ) {
		if ( is_array( $recipients ) ) {
			return implode( ', ', array_map( 'strval', $recipients ) );
		}

		return (string) $recipients;
	}

	/**
	 * Returns readable placeholder text for empty table values.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function fallback_text( $value ) {
		return '' === trim( $value ) ? 'n/a' : $value;
	}

	/**
	 * Returns an escaped status badge.
	 *
	 * @param string $status Status or event slug.
	 * @return string
	 */
	private function status_badge( $status ) {
		$status = sanitize_key( $status );

		if ( '' === $status ) {
			return '<span class="wmqt-badge wmqt-badge-empty">n/a</span>';
		}

		return sprintf(
			'<span class="wmqt-badge wmqt-badge-%1$s">%2$s</span>',
			esc_attr( $status ),
			esc_html( ucwords( str_replace( '_', ' ', $status ) ) )
		);
	}

	/**
	 * Renders pagination links for table screens.
	 *
	 * @param string               $page_slug Admin page slug.
	 * @param int                  $paged Current page.
	 * @param int                  $total Total rows.
	 * @param array<string,string> $args Extra query args.
	 * @return void
	 */
	private function render_pagination( $page_slug, $paged, $total, array $args = array() ) {
		$total_pages = (int) ceil( max( 0, $total ) / self::PER_PAGE );

		if ( 2 > $total_pages ) {
			return;
		}

			$query_args = array_filter(
				array_merge( array( 'page' => $page_slug ), $args ),
				static function ( $value ) {
					return '' !== (string) $value;
				}
			);
		$base = add_query_arg( array_merge( $query_args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) );

		echo '<div class="wmqt-pagination">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => esc_url_raw( $base ),
					'format'    => '',
					'current'   => max( 1, (int) $paged ),
					'total'     => $total_pages,
					'prev_text' => __( 'Previous', 'monte-mail-queue-throttle' ),
					'next_text' => __( 'Next', 'monte-mail-queue-throttle' ),
				)
			)
		);
		echo '</div>';
	}

	/**
	 * Formats a cron timestamp.
	 *
	 * @param int|false $timestamp Cron timestamp.
	 * @return string
	 */
	private function format_timestamp( $timestamp ) {
		if ( ! $timestamp ) {
			return __( 'Not scheduled', 'monte-mail-queue-throttle' );
		}

		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $timestamp );
	}
}
