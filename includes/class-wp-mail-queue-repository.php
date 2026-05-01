<?php
/**
 * Queue and log persistence.
 *
 * @package WP_Mail_Queue_Throttle
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

		$now = current_time( 'mysql' );

		$inserted = $wpdb->insert(
			$this->queue_table(),
			array(
				'recipients'     => $this->encode_json( $mail['to'] ?? '' ),
				'subject'        => (string) ( $mail['subject'] ?? '' ),
				'message'        => (string) ( $mail['message'] ?? '' ),
				'headers'        => $this->encode_json( $mail['headers'] ?? '' ),
				'attachments'    => $this->encode_json( $mail['attachments'] ?? array() ),
				'source_plugin'  => sanitize_key( $source_plugin ),
				'status'         => 'queued',
				'attempts'       => 0,
				'max_attempts'   => max( 1, absint( $this->settings->get( 'max_attempts', 3 ) ) ),
				'last_error'     => null,
				'queued_at'      => $now,
				'updated_at'     => $now,
				'sent_at'        => null,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
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
	 * Returns recent queue items.
	 *
	 * @param string $status Optional status filter.
	 * @param int    $limit Row limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function queue_items( string $status = '', int $limit = 100 ): array {
		global $wpdb;

		$limit  = max( 1, absint( $limit ) );
		$status = sanitize_key( $status );
		$table  = $this->queue_table();

		if ( '' !== $status ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d",
					$status,
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
				ARRAY_A
			);
		}

		return array_map( array( $this, 'decode_queue_row' ), (array) $rows );
	}

	/**
	 * Returns recent log entries.
	 *
	 * @param int $limit Row limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function logs( int $limit = 100 ): array {
		global $wpdb;

		$table = $this->logs_table();

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max( 1, absint( $limit ) ) ),
			ARRAY_A
		);
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
	 * @return string
	 */
	private function encode_json( $value ): string {
		$encoded = wp_json_encode( $value );

		return false === $encoded ? 'null' : $encoded;
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
