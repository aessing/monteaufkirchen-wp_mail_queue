<?php
/**
 * Admin views for WP Mail Queue Throttle.
 *
 * @package WP_Mail_Queue_Throttle
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and handles plugin admin screens.
 */
class WP_Mail_Queue_Admin {
	const MENU_SLUG = 'wp-mail-queue';

	/**
	 * Settings dependency.
	 *
	 * @var WP_Mail_Queue_Settings
	 */
	private $settings;

	/**
	 * Repository dependency.
	 *
	 * @var WP_Mail_Queue_Repository
	 */
	private $repository;

	/**
	 * Registered page hooks.
	 *
	 * @var array<string, bool>
	 */
	private $page_hooks = array();

	/**
	 * Constructor.
	 *
	 * @param WP_Mail_Queue_Settings   $settings Settings dependency.
	 * @param WP_Mail_Queue_Repository $repository Repository dependency.
	 */
	public function __construct( WP_Mail_Queue_Settings $settings, WP_Mail_Queue_Repository $repository ) {
		$this->settings   = $settings;
		$this->repository = $repository;
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
			__( 'Mail Queue', 'wp-mail-queue-throttle' ),
			__( 'Mail Queue', 'wp-mail-queue-throttle' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-email-alt2',
			80
		) ] = true;

		$this->page_hooks[ add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'wp-mail-queue-throttle' ),
			__( 'Dashboard', 'wp-mail-queue-throttle' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard' )
		) ] = true;

		$this->page_hooks[ add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'wp-mail-queue-throttle' ),
			__( 'Settings', 'wp-mail-queue-throttle' ),
			'manage_options',
			'wp-mail-queue-settings',
			array( $this, 'render_settings' )
		) ] = true;

		$this->page_hooks[ add_submenu_page(
			self::MENU_SLUG,
			__( 'Queue', 'wp-mail-queue-throttle' ),
			__( 'Queue', 'wp-mail-queue-throttle' ),
			'manage_options',
			'wp-mail-queue-items',
			array( $this, 'render_queue' )
		) ] = true;

		$this->page_hooks[ add_submenu_page(
			self::MENU_SLUG,
			__( 'Logs', 'wp-mail-queue-throttle' ),
			__( 'Logs', 'wp-mail-queue-throttle' ),
			'manage_options',
			'wp-mail-queue-logs',
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
			'wp-mail-queue-admin',
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
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-mail-queue-throttle' ) );
		}

		$counts        = $this->repository->counts();
		$settings      = $this->settings->get_all();
		$rate          = max( 1, absint( $settings['rate_per_minute'] ?? 25 ) );
		$per_run_limit = $rate * 2;
		$next_cron     = wp_next_scheduled( WMQT_CRON_HOOK );
		$cards         = array(
			array( __( 'Queued', 'wp-mail-queue-throttle' ), (int) ( $counts['queued'] ?? 0 ) ),
			array( __( 'Processing', 'wp-mail-queue-throttle' ), (int) ( $counts['processing'] ?? 0 ) ),
			array( __( 'Sent', 'wp-mail-queue-throttle' ), (int) ( $counts['sent'] ?? 0 ) ),
			array( __( 'Failed', 'wp-mail-queue-throttle' ), (int) ( $counts['failed'] ?? 0 ) ),
			array( __( 'Configured rate', 'wp-mail-queue-throttle' ), sprintf( _n( '%d mail/min', '%d mails/min', $rate, 'wp-mail-queue-throttle' ), $rate ) ),
			array( __( 'Per-run limit', 'wp-mail-queue-throttle' ), $per_run_limit ),
			array( __( 'Next cron', 'wp-mail-queue-throttle' ), $this->format_timestamp( $next_cron ) ),
		);

		echo '<div class="wrap wmqt-admin">';
		echo '<h1>' . esc_html__( 'Mail Queue Dashboard', 'wp-mail-queue-throttle' ) . '</h1>';
		echo '<div class="wmqt-actions">';
		$this->render_admin_link( 'wp-mail-queue-settings', __( 'Settings', 'wp-mail-queue-throttle' ) );
		$this->render_admin_link( 'wp-mail-queue-items', __( 'Queue', 'wp-mail-queue-throttle' ) );
		$this->render_admin_link( 'wp-mail-queue-logs', __( 'Logs', 'wp-mail-queue-throttle' ) );
		echo '</div>';
		echo '<div class="wmqt-card-grid">';

		foreach ( $cards as $card ) {
			echo '<div class="wmqt-card">';
			echo '<h2>' . esc_html( $card[0] ) . '</h2>';
			echo '<p>' . esc_html( (string) $card[1] ) . '</p>';
			echo '</div>';
		}

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Renders and saves the settings page.
	 *
	 * @return void
	 */
	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-mail-queue-throttle' ) );
		}

		if ( isset( $_POST['wmqt_settings_nonce'] ) ) {
			$this->save_settings();
		}

		$settings = $this->settings->get_all();

		echo '<div class="wrap wmqt-admin">';
		echo '<h1>' . esc_html__( 'Mail Queue Settings', 'wp-mail-queue-throttle' ) . '</h1>';
		settings_errors( 'wmqt_messages' );
		echo '<form method="post" action="">';
		wp_nonce_field( 'wmqt_save_settings', 'wmqt_settings_nonce' );
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_number_field( 'rate_per_minute', __( 'Mails per minute', 'wp-mail-queue-throttle' ), $settings['rate_per_minute'] ?? 25 );
		$this->render_number_field( 'max_attempts', __( 'Max retries', 'wp-mail-queue-throttle' ), $settings['max_attempts'] ?? 3 );
		$this->render_queue_mode_field( (string) ( $settings['queue_mode'] ?? 'all' ) );
		$this->render_text_field( 'allowed_plugins', __( 'Allowed plugin slugs', 'wp-mail-queue-throttle' ), $settings['allowed_plugins'] ?? '' );
		$this->render_number_field( 'log_retention_days', __( 'Log retention days', 'wp-mail-queue-throttle' ), $settings['log_retention_days'] ?? 30 );
		echo '</tbody></table>';
		submit_button( __( 'Save Settings', 'wp-mail-queue-throttle' ) );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Renders the queue table.
	 *
	 * @return void
	 */
	public function render_queue() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-mail-queue-throttle' ) );
		}

		$status = $this->requested_status();
		$items  = $this->repository->queue_items( $status );

		echo '<div class="wrap wmqt-admin">';
		echo '<h1>' . esc_html__( 'Mail Queue', 'wp-mail-queue-throttle' ) . '</h1>';
		$this->render_status_filter( $status );
		echo '<table class="widefat striped wmqt-table">';
		echo '<thead><tr>';
		$this->render_table_headers( array( 'ID', 'Recipients', 'Subject', 'Source plugin', 'Status', 'Attempts', 'Last error', 'Queued', 'Sent' ) );
		echo '</tr></thead><tbody>';

		if ( empty( $items ) ) {
			echo '<tr><td colspan="9">' . esc_html__( 'No queue items found.', 'wp-mail-queue-throttle' ) . '</td></tr>';
		}

		foreach ( $items as $item ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) (int) ( $item['id'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_recipients( $item['to'] ?? array() ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $item['subject'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $item['source_plugin'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $item['status'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) (int) ( $item['attempts'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $item['last_error'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $item['queued_at'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $item['sent_at'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Renders the logs table.
	 *
	 * @return void
	 */
	public function render_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-mail-queue-throttle' ) );
		}

		$logs = $this->repository->logs();

		echo '<div class="wrap wmqt-admin">';
		echo '<h1>' . esc_html__( 'Mail Queue Logs', 'wp-mail-queue-throttle' ) . '</h1>';
		echo '<table class="widefat striped wmqt-table">';
		echo '<thead><tr>';
		$this->render_table_headers( array( 'Timestamp', 'Event type', 'Queue item ID', 'Source plugin', 'Message' ) );
		echo '</tr></thead><tbody>';

		if ( empty( $logs ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No log entries found.', 'wp-mail-queue-throttle' ) . '</td></tr>';
		}

		foreach ( $logs as $log ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $log['created_at'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $log['event_type'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) (int) ( $log['queue_id'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $log['source_plugin'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $log['message'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Saves submitted settings.
	 *
	 * @return void
	 */
	private function save_settings() {
		check_admin_referer( 'wmqt_save_settings', 'wmqt_settings_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to save these settings.', 'wp-mail-queue-throttle' ) );
		}

		$this->settings->update(
			array(
				'rate_per_minute'   => isset( $_POST['rate_per_minute'] ) ? wp_unslash( $_POST['rate_per_minute'] ) : 25,
				'max_attempts'      => isset( $_POST['max_attempts'] ) ? wp_unslash( $_POST['max_attempts'] ) : 3,
				'queue_mode'        => isset( $_POST['queue_mode'] ) ? wp_unslash( $_POST['queue_mode'] ) : 'all',
				'allowed_plugins'   => isset( $_POST['allowed_plugins'] ) ? wp_unslash( $_POST['allowed_plugins'] ) : '',
				'log_retention_days' => isset( $_POST['log_retention_days'] ) ? wp_unslash( $_POST['log_retention_days'] ) : 30,
			)
		);

		add_settings_error(
			'wmqt_messages',
			'wmqt_settings_saved',
			__( 'Settings saved.', 'wp-mail-queue-throttle' ),
			'updated'
		);
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
	 * Renders a positive integer settings field.
	 *
	 * @param string $name Field name.
	 * @param string $label Field label.
	 * @param mixed  $value Field value.
	 * @return void
	 */
	private function render_number_field( $name, $label, $value ) {
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input name="%1$s" id="%1$s" type="number" min="1" value="%3$s" class="small-text"></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( (string) max( 1, absint( $value ) ) )
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
	private function render_text_field( $name, $label, $value ) {
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input name="%1$s" id="%1$s" type="text" value="%3$s" class="regular-text"></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( (string) $value )
		);
	}

	/**
	 * Renders the queue mode radio field.
	 *
	 * @param string $selected Selected queue mode.
	 * @return void
	 */
	private function render_queue_mode_field( $selected ) {
		$selected = in_array( $selected, array( 'all', 'selected' ), true ) ? $selected : 'all';

		echo '<tr><th scope="row">' . esc_html__( 'Queue mode', 'wp-mail-queue-throttle' ) . '</th><td><fieldset>';
		printf(
			'<label><input type="radio" name="queue_mode" value="all" %s> %s</label><br>',
			checked( 'all', $selected, false ),
			esc_html__( 'All mails', 'wp-mail-queue-throttle' )
		);
		printf(
			'<label><input type="radio" name="queue_mode" value="selected" %s> %s</label>',
			checked( 'selected', $selected, false ),
			esc_html__( 'Selected plugin slugs', 'wp-mail-queue-throttle' )
		);
		echo '</fieldset></td></tr>';
	}

	/**
	 * Renders the queue status filter.
	 *
	 * @param string $selected Selected status.
	 * @return void
	 */
	private function render_status_filter( $selected ) {
		$statuses = array(
			''           => __( 'All statuses', 'wp-mail-queue-throttle' ),
			'queued'     => __( 'Queued', 'wp-mail-queue-throttle' ),
			'processing' => __( 'Processing', 'wp-mail-queue-throttle' ),
			'sent'       => __( 'Sent', 'wp-mail-queue-throttle' ),
			'failed'     => __( 'Failed', 'wp-mail-queue-throttle' ),
		);

		echo '<form method="get" class="wmqt-filter">';
		echo '<input type="hidden" name="page" value="wp-mail-queue-items">';
		echo '<label for="wmqt-status-filter">' . esc_html__( 'Status', 'wp-mail-queue-throttle' ) . '</label> ';
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
		submit_button( __( 'Filter', 'wp-mail-queue-throttle' ), 'secondary', '', false );
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
	private function requested_status() {
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		return in_array( $status, array( 'queued', 'processing', 'sent', 'failed' ), true ) ? $status : '';
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
	 * Formats a cron timestamp.
	 *
	 * @param int|false $timestamp Cron timestamp.
	 * @return string
	 */
	private function format_timestamp( $timestamp ) {
		if ( ! $timestamp ) {
			return __( 'Not scheduled', 'wp-mail-queue-throttle' );
		}

		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $timestamp );
	}
}
