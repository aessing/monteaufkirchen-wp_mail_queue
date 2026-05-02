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
class WP_Mail_Queue_Admin {
	const MENU_SLUG = 'monte-mail-queue';
	const PER_PAGE  = 50;

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
			__( 'Mail Queue', 'monte-mail-queue-throttle' ),
			__( 'Mail Queue', 'monte-mail-queue-throttle' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-email-alt2',
			80
		) ] = true;

		$this->page_hooks[ add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'monte-mail-queue-throttle' ),
			__( 'Dashboard', 'monte-mail-queue-throttle' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard' )
		) ] = true;

		$this->page_hooks[ add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'monte-mail-queue-throttle' ),
			__( 'Settings', 'monte-mail-queue-throttle' ),
			'manage_options',
			'monte-mail-queue-settings',
			array( $this, 'render_settings' )
		) ] = true;

		$this->page_hooks[ add_submenu_page(
			self::MENU_SLUG,
			__( 'Queue', 'monte-mail-queue-throttle' ),
			__( 'Queue', 'monte-mail-queue-throttle' ),
			'manage_options',
			'monte-mail-queue-items',
			array( $this, 'render_queue' )
		) ] = true;

		$this->page_hooks[ add_submenu_page(
			self::MENU_SLUG,
			__( 'Logs', 'monte-mail-queue-throttle' ),
			__( 'Logs', 'monte-mail-queue-throttle' ),
			'manage_options',
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
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'monte-mail-queue-throttle' ) );
		}

		$counts        = $this->repository->counts();
		$settings      = $this->settings->get_all();
		$rate          = max( 1, absint( $settings['rate_per_minute'] ?? 25 ) );
		$per_run_limit = $rate * 2;
		$next_cron     = wp_next_scheduled( WMQT_CRON_HOOK );
		$chart_data    = $this->repository->daily_status_counts( 30 );
		$active_total  = $this->repository->queue_items_count( 'active' );
		$active_items  = $this->repository->queue_items( 'active', 10 );
		$cards         = array(
			array( __( 'Queued', 'monte-mail-queue-throttle' ), (int) ( $counts['queued'] ?? 0 ) ),
			array( __( 'Processing', 'monte-mail-queue-throttle' ), (int) ( $counts['processing'] ?? 0 ) ),
			array( __( 'Sent', 'monte-mail-queue-throttle' ), (int) ( $counts['sent'] ?? 0 ) ),
			array( __( 'Failed', 'monte-mail-queue-throttle' ), (int) ( $counts['failed'] ?? 0 ) ),
			array( __( 'Configured rate', 'monte-mail-queue-throttle' ), sprintf( _n( '%d mail/min', '%d mails/min', $rate, 'monte-mail-queue-throttle' ), $rate ) ),
			array( __( 'Per-run limit', 'monte-mail-queue-throttle' ), $per_run_limit ),
			array( __( 'Next cron', 'monte-mail-queue-throttle' ), $this->format_timestamp( $next_cron ) ),
		);

		echo '<div class="wrap wmqt-admin">';
		echo '<h1>' . esc_html__( 'Mail Queue Dashboard', 'monte-mail-queue-throttle' ) . '</h1>';
		echo '<div class="wmqt-actions">';
		$this->render_admin_link( 'monte-mail-queue-settings', __( 'Settings', 'monte-mail-queue-throttle' ) );
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
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'monte-mail-queue-throttle' ) );
		}

		if ( isset( $_POST['wmqt_settings_nonce'] ) ) {
			$this->save_settings();
		}

		$settings = $this->settings->get_all();

		echo '<div class="wrap wmqt-admin">';
		echo '<h1>' . esc_html__( 'Mail Queue Settings', 'monte-mail-queue-throttle' ) . '</h1>';
		settings_errors( 'wmqt_messages' );
		echo '<form method="post" action="">';
		wp_nonce_field( 'wmqt_save_settings', 'wmqt_settings_nonce' );
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_number_field( 'rate_per_minute', __( 'Mails per minute', 'monte-mail-queue-throttle' ), $settings['rate_per_minute'] ?? 25 );
		$this->render_number_field( 'max_attempts', __( 'Max retries', 'monte-mail-queue-throttle' ), $settings['max_attempts'] ?? 3 );
		$this->render_queue_mode_field( (string) ( $settings['queue_mode'] ?? 'all' ) );
		$this->render_text_field( 'allowed_plugins', __( 'Allowed plugin slugs', 'monte-mail-queue-throttle' ), $settings['allowed_plugins'] ?? '' );
		$this->render_number_field( 'log_retention_days', __( 'Log retention days', 'monte-mail-queue-throttle' ), $settings['log_retention_days'] ?? 30 );
		echo '</tbody></table>';
		submit_button( __( 'Save Settings', 'monte-mail-queue-throttle' ) );
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
		$this->render_pagination( 'monte-mail-queue-items', $paged, $total, array( 'status' => $status ) );
		echo '</div>';
	}

	/**
	 * Renders the logs table.
	 *
	 * @return void
	 */
	public function render_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
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

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to save these settings.', 'monte-mail-queue-throttle' ) );
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
			__( 'Settings saved.', 'monte-mail-queue-throttle' ),
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
		echo '<p>' . esc_html__( 'Daily queue outcomes use the same status colors as the tables.', 'monte-mail-queue-throttle' ) . '</p>';
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
		echo '<div class="wmqt-chart" role="img" aria-label="' . esc_attr__( 'Stacked daily mail status chart for the last 30 days.', 'monte-mail-queue-throttle' ) . '">';
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

		return in_array( $event_type, array( 'queued', 'sent', 'retry', 'failed', 'recovered', 'encode_failed', 'enqueue_failed' ), true ) ? $event_type : '';
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
				return '' !== (string) $value && 'active' !== (string) $value;
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
