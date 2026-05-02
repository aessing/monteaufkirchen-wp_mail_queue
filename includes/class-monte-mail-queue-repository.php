<?php
/**
 * Queue and log persistence.
 *
 * @package Monte_Mail_Queue_Throttle
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists queued mail payloads and queue events.
 */
class WP_Mail_Queue_Repository {
	/**
	 * Settings dependency.
	 *
	 * @var WP_Mail_Queue_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param WP_Mail_Queue_Settings $settings Settings dependency.
	 */
	public function __construct( WP_Mail_Queue_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Stores a mail payload in the queue.
	 *
	 * @param array<string, mixed> $mail Mail payload.
	 * @param string               $source_plugin Source plugin slug.
	 * @return int
	 */
	public function enqueue( array $mail, string $source_plugin = '' ): int {
		global $wpdb;

		$now         = current_time( 'mysql' );
		$recipients  = $this->encode_json( $mail['to'] ?? '' );
		$headers     = $this->encode_json( $mail['headers'] ?? '' );
		$attachments = $this->encode_json( $mail['attachments'] ?? array() );
		$source      = sanitize_key( $source_plugin );

		if ( false === $recipients || false === $headers || false === $attachments ) {
			$this->log( 0, 'encode_failed', 'Mail payload could not be JSON encoded; continuing normal wp_mail delivery.', $source );
			return 0;
		}

		$inserted = $wpdb->insert(
			$this->queue_table(),
			array(
				'recipients'    => $recipients,
				'subject'       => (string) ( $mail['subject'] ?? '' ),
				'message'       => (string) ( $mail['message'] ?? '' ),
				'headers'       => $headers,
				'attachments'   => $attachments,
				'source_plugin' => $source,
				'status'        => 'queued',
				'attempts'      => 0,
				'max_attempts'  => max( 1, absint( $this->settings->get( 'max_attempts', 3 ) ) ),
				'queued_at'     => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Claims the next queued rows for processing.
	 *
	 * @param int $limit Batch size.
	 * @return array<int, array<string, mixed>>
	 */
	public function claim_batch( int $limit ): array {
		global $wpdb;

		$limit = max( 1, absint( $limit ) );
		$table = $this->queue_table();

		$this->recover_stale_processing_items();

		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT %d",
				'queued',
				$limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$claimed = array();
		$now     = current_time( 'mysql' );

		foreach ( (array) $rows as $row ) {
			$updated = $wpdb->update(
				$this->queue_table(),
				array(
					'status'     => 'processing',
					'updated_at' => $now,
				),
				array(
					'id'     => (int) $row['id'],
					'status' => 'queued',
				),
				array( '%s', '%s' ),
				array( '%d', '%s' )
			);

			if ( 1 === $updated ) {
				$row['status']     = 'processing';
				$row['updated_at'] = $now;
				$claimed[]         = $this->decode_queue_row( $row );
			}
		}

		return $claimed;
	}

	/**
	 * Requeues processing rows that were claimed by an interrupted worker.
	 *
	 * @return void
	 */
	private function recover_stale_processing_items(): void {
		global $wpdb;

		$table   = $this->queue_table();
		$now     = current_time( 'mysql' );
		$message = 'Recovered stale processing lock after timeout.';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source_plugin FROM {$table} WHERE status = %s AND updated_at < DATE_SUB(%s, INTERVAL 15 MINUTE)",
				'processing',
				$now
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( (array) $rows as $row ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = %s, last_error = %s, updated_at = %s WHERE id = %d AND status = %s AND updated_at < DATE_SUB(%s, INTERVAL 15 MINUTE)",
					'queued',
					$message,
					$now,
					(int) $row['id'],
					'processing',
					$now
				)
			);

			if ( 1 === $updated ) {
				$this->log( (int) $row['id'], 'recovered', $message, (string) $row['source_plugin'] );
			}
		}
	}

	/**
	 * Marks a queue item as sent.
	 *
	 * @param int $id Queue item ID.
	 * @return void
	 */
	public function mark_sent( int $id ): void {
		global $wpdb;

		$now = current_time( 'mysql' );

		$wpdb->update(
			$this->queue_table(),
			array(
				'status'     => 'sent',
				'last_error' => null,
				'updated_at' => $now,
				'sent_at'    => $now,
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Records a retryable failure and returns the item to the queue.
	 *
	 * @param int    $id Queue item ID.
	 * @param string $error Error message.
	 * @return void
	 */
	public function mark_retry( int $id, string $error ): void {
		global $wpdb;

		$table = $this->queue_table();

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, attempts = attempts + 1, last_error = %s, updated_at = %s WHERE id = %d",
				'queued',
				$error,
				current_time( 'mysql' ),
				absint( $id )
			)
		);
	}

	/**
	 * Marks a queue item as permanently failed.
	 *
	 * @param int    $id Queue item ID.
	 * @param string $error Error message.
	 * @return void
	 */
	public function mark_failed( int $id, string $error ): void {
		global $wpdb;

		$table = $this->queue_table();

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, attempts = attempts + 1, last_error = %s, updated_at = %s WHERE id = %d",
				'failed',
				$error,
				current_time( 'mysql' ),
				absint( $id )
			)
		);
	}

	/**
	 * Stores a queue event.
	 *
	 * @param int    $queue_id Queue item ID.
	 * @param string $event_type Event type.
	 * @param string $message Event message.
	 * @param string $source_plugin Source plugin slug.
	 * @return void
	 */
	public function log( int $queue_id, string $event_type, string $message, string $source_plugin = '' ): void {
		global $wpdb;

		$wpdb->insert(
			$this->logs_table(),
			array(
				'queue_id'      => absint( $queue_id ),
				'event_type'    => sanitize_key( $event_type ),
				'message'       => $message,
				'source_plugin' => sanitize_key( $source_plugin ),
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Returns queue counts by status.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		global $wpdb;

		$counts = array(
			'queued'     => 0,
			'processing' => 0,
			'sent'       => 0,
			'failed'     => 0,
		);
		$table  = $this->queue_table();
		$rows   = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$status = (string) $row['status'];

			$counts[ $status ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Returns daily queue status counts for the last N days.
	 *
	 * @param int $days Number of days to include.
	 * @return array{days: array<int, array<string, mixed>>, max_total: int, totals: array<string, int>}
	 */
	public function daily_status_counts( int $days = 30 ): array {
		global $wpdb;

		$days      = min( 90, max( 1, absint( $days ) ) );
		$today     = current_datetime();
		$start     = $today->sub( new DateInterval( 'P' . ( $days - 1 ) . 'D' ) )->format( 'Y-m-d' );
		$queue     = $this->queue_table();
		$statuses  = array( 'queued', 'processing', 'failed', 'sent' );
		$day_index = array();
		$totals    = array_fill_keys( $statuses, 0 );
		$max_total = 0;

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day = current_datetime()->sub( new DateInterval( 'P' . $i . 'D' ) )->format( 'Y-m-d' );

			$day_index[ $day ] = array(
				'date'       => $day,
				'label'      => date_i18n( 'M j', strtotime( $day . ' 00:00:00' ) ),
				'queued'     => 0,
				'processing' => 0,
				'failed'     => 0,
				'sent'       => 0,
				'total'      => 0,
			);
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"(SELECT DATE(queued_at) AS day, status, COUNT(*) AS total FROM {$queue} WHERE status IN (%s, %s) AND queued_at >= %s GROUP BY DATE(queued_at), status)
				UNION ALL
				(SELECT DATE(updated_at) AS day, %s AS status, COUNT(*) AS total FROM {$queue} WHERE status = %s AND updated_at >= %s GROUP BY DATE(updated_at))
				UNION ALL
				(SELECT DATE(sent_at) AS day, %s AS status, COUNT(*) AS total FROM {$queue} WHERE status = %s AND sent_at IS NOT NULL AND sent_at >= %s GROUP BY DATE(sent_at))",
				'queued',
				'processing',
				$start . ' 00:00:00',
				'failed',
				'failed',
				$start . ' 00:00:00',
				'sent',
				'sent',
				$start . ' 00:00:00'
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$day    = (string) ( $row['day'] ?? '' );
			$status = sanitize_key( (string) ( $row['status'] ?? '' ) );
			$total  = (int) ( $row['total'] ?? 0 );

			if ( ! isset( $day_index[ $day ] ) || ! in_array( $status, $statuses, true ) ) {
				continue;
			}

			$day_index[ $day ][ $status ] += $total;
			$day_index[ $day ]['total']   += $total;
			$totals[ $status ]            += $total;
			$max_total                     = max( $max_total, (int) $day_index[ $day ]['total'] );
		}

		return array(
			'days'      => array_values( $day_index ),
			'max_total' => max( 1, $max_total ),
			'totals'    => $totals,
		);
	}

	/**
	 * Returns recent queue items.
	 *
	 * @param string $status Optional status filter.
	 * @param int    $limit Row limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function queue_items( string $status = 'active', int $limit = 100, int $offset = 0 ): array {
		global $wpdb;

		$limit  = min( 200, max( 1, absint( $limit ) ) );
		$offset = max( 0, absint( $offset ) );
		$status = sanitize_key( $status );
		$table  = $this->queue_table();

		if ( 'active' === $status || '' === $status ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status IN (%s, %s) ORDER BY id DESC LIMIT %d OFFSET %d",
					'queued',
					'processing',
					$limit,
					$offset
				),
				ARRAY_A
			);
		} elseif ( in_array( $status, array( 'queued', 'processing', 'sent', 'failed' ), true ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d",
					$status,
					$limit,
					$offset
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ),
				ARRAY_A
			);
		}

		return array_map( array( $this, 'decode_queue_row' ), (array) $rows );
	}

	/**
	 * Counts queue items for an admin status filter.
	 *
	 * @param string $status Optional status filter.
	 * @return int
	 */
	public function queue_items_count( string $status = 'active' ): int {
		global $wpdb;

		$status = sanitize_key( $status );
		$table  = $this->queue_table();

		if ( 'active' === $status || '' === $status ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE status IN (%s, %s)",
					'queued',
					'processing'
				)
			);
		}

		if ( in_array( $status, array( 'queued', 'processing', 'sent', 'failed' ), true ) ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status )
			);
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Returns recent log entries.
	 *
	 * @param string $event_type Optional event filter.
	 * @param int    $limit Row limit.
	 * @param int    $offset Row offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function logs( string $event_type = '', int $limit = 100, int $offset = 0 ): array {
		global $wpdb;

		$limit       = min( 200, max( 1, absint( $limit ) ) );
		$offset      = max( 0, absint( $offset ) );
		$event_type  = sanitize_key( $event_type );
		$logs_table  = $this->logs_table();
		$queue_table = $this->queue_table();
		$select      = "SELECT l.*, q.recipients, q.subject, q.status AS queue_status, q.attempts, q.last_error, q.queued_at, q.sent_at, COALESCE(NULLIF(l.source_plugin, ''), q.source_plugin, '') AS resolved_source_plugin FROM {$logs_table} l LEFT JOIN {$queue_table} q ON q.id = l.queue_id";

		if ( '' !== $event_type ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"{$select} WHERE l.event_type = %s ORDER BY l.id DESC LIMIT %d OFFSET %d",
					$event_type,
					$limit,
					$offset
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "{$select} ORDER BY l.id DESC LIMIT %d OFFSET %d", $limit, $offset ),
				ARRAY_A
			);
		}

		return array_map( array( $this, 'decode_log_row' ), (array) $rows );
	}

	/**
	 * Counts log rows for an event filter.
	 *
	 * @param string $event_type Optional event filter.
	 * @return int
	 */
	public function logs_count( string $event_type = '' ): int {
		global $wpdb;

		$event_type = sanitize_key( $event_type );
		$table      = $this->logs_table();

		if ( '' !== $event_type ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = %s", $event_type )
			);
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Deletes old log rows according to configured retention.
	 *
	 * @return int Deleted row count.
	 */
	public function purge_old_logs(): int {
		global $wpdb;

		$days  = max( 1, absint( $this->settings->get( 'log_retention_days', 30 ) ) );
		$table = $this->logs_table();

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < DATE_SUB(%s, INTERVAL %d DAY)",
				current_time( 'mysql' ),
				$days
			)
		);

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Returns the queue table name.
	 *
	 * @return string
	 */
	private function queue_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wmqt_queue';
	}

	/**
	 * Returns the logs table name.
	 *
	 * @return string
	 */
	private function logs_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wmqt_logs';
	}

	/**
	 * Encodes a value for JSON storage.
	 *
	 * @param mixed $value Value to encode.
	 * @return string|false
	 */
	private function encode_json( $value ) {
		return wp_json_encode( $value );
	}

	/**
	 * Decodes one queue row for callers.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private function decode_queue_row( array $row ): array {
		$row['id']           = (int) $row['id'];
		$row['to']           = $this->decode_json( $row['recipients'] ?? '[]' );
		$row['headers']      = $this->decode_json( $row['headers'] ?? '[]' );
		$row['attachments']  = $this->decode_json( $row['attachments'] ?? '[]' );
		$row['attempts']     = (int) $row['attempts'];
		$row['max_attempts'] = (int) $row['max_attempts'];

		return $row;
	}

	/**
	 * Decodes one log row and attached queue data for callers.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private function decode_log_row( array $row ): array {
		$row['id']            = (int) ( $row['id'] ?? 0 );
		$row['queue_id']      = (int) ( $row['queue_id'] ?? 0 );
		$row['to']            = $this->decode_json( $row['recipients'] ?? '[]' );
		$row['attempts']      = isset( $row['attempts'] ) ? (int) $row['attempts'] : 0;
		$row['source_plugin'] = (string) ( $row['resolved_source_plugin'] ?? $row['source_plugin'] ?? '' );

		return $row;
	}

	/**
	 * Decodes a JSON value.
	 *
	 * @param mixed $value JSON value.
	 * @return mixed
	 */
	private function decode_json( $value ) {
		$decoded = json_decode( (string) $value, true );

		return JSON_ERROR_NONE === json_last_error() ? $decoded : array();
	}
}
