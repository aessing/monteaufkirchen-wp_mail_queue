<?php
/**
 * Cron worker for queued wp_mail() payloads.
 *
 * @package Monte_Mail_Queue_Throttle
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes queued mail in throttled batches.
 */
class Monte_Mail_Queue_Worker {
	const SOFT_DEADLINE_BUFFER = 5;
	const FALLBACK_DEADLINE    = 110;

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
	 * Interceptor dependency.
	 *
	 * @var Monte_Mail_Queue_Interceptor
	 */
	private $interceptor;

	/**
	 * Constructor.
	 *
	 * @param Monte_Mail_Queue_Settings    $settings Settings dependency.
	 * @param Monte_Mail_Queue_Repository  $repository Repository dependency.
	 * @param Monte_Mail_Queue_Interceptor $interceptor Interceptor dependency.
	 */
	public function __construct(
		Monte_Mail_Queue_Settings $settings,
		Monte_Mail_Queue_Repository $repository,
		Monte_Mail_Queue_Interceptor $interceptor
	) {
		$this->settings    = $settings;
		$this->repository  = $repository;
		$this->interceptor = $interceptor;
	}

	/**
	 * Processes one cron batch.
	 *
	 * @return void
	 */
	public function process_queue() {
		$limit    = max( 1, absint( $this->settings->get( 'rate_per_minute', 25 ) ) * 2 );
		$deadline = $this->deadline_timestamp();
		$sent     = 0;

		$this->repository->recover_stale_processing_items();

		while ( $sent < $limit && time() < $deadline ) {
			$items = $this->repository->claim_batch( 1 );

			if ( empty( $items ) ) {
				break;
			}

			$this->process_item( $items[0] );
			$sent++;
		}

		$this->repository->purge_old_logs();
		$this->repository->purge_old_queue_items();
	}

	/**
	 * Replays one queued mail payload.
	 *
	 * @param array<string, mixed> $item Queue item.
	 * @return void
	 */
	private function process_item( array $item ) {
		$id            = (int) ( $item['id'] ?? 0 );
		$source_plugin = isset( $item['source_plugin'] ) ? sanitize_key( (string) $item['source_plugin'] ) : '';
		$missing       = $this->missing_attachments( $item['attachments'] ?? array() );

		if ( ! empty( $missing ) ) {
			$this->repository->log( $id, 'attachment_missing', 'Attachment path no longer exists: ' . implode( ', ', $missing ), $source_plugin );
		}

		try {
			$this->interceptor->enable_bypass();

			$sent = wp_mail(
				$item['to'] ?? '',
				(string) ( $item['subject'] ?? '' ),
				(string) ( $item['message'] ?? '' ),
				$item['headers'] ?? '',
				$item['attachments'] ?? array()
			);

			if ( true === $sent ) {
				if ( $this->repository->mark_sent( $id ) ) {
					$this->repository->log( $id, 'sent', 'Mail sent successfully.', $source_plugin );
				}
				return;
			}

			$this->record_failure( $item, 'wp_mail returned false.' );
		} catch ( Throwable $throwable ) {
			$this->record_failure( $item, $throwable->getMessage() );
		} finally {
			$this->interceptor->disable_bypass();
		}
	}

	/**
	 * Records a retryable or final failure.
	 *
	 * @param array<string, mixed> $item Queue item.
	 * @param string               $error Error message.
	 * @return void
	 */
	private function record_failure( array $item, $error ) {
		$id            = (int) ( $item['id'] ?? 0 );
		$attempts      = (int) ( $item['attempts'] ?? 0 );
		$max_attempts  = max( 1, (int) ( $item['max_attempts'] ?? 1 ) );
		$source_plugin = isset( $item['source_plugin'] ) ? sanitize_key( (string) $item['source_plugin'] ) : '';
		$error         = '' !== (string) $error ? (string) $error : 'Unknown mail send failure.';

		if ( $attempts + 1 >= $max_attempts ) {
			if ( $this->repository->mark_failed( $id, $error ) ) {
				$this->repository->log( $id, 'failed', $error, $source_plugin );
			}
			return;
		}

		if ( $this->repository->mark_retry( $id, $error, $this->retry_delay_seconds( $attempts + 1 ) ) ) {
			$this->repository->log( $id, 'retry', $error, $source_plugin );
		}
	}

	/**
	 * Calculates the soft deadline for one worker request.
	 *
	 * @return int Unix timestamp.
	 */
	private function deadline_timestamp() {
		$max_execution_time = (int) ini_get( 'max_execution_time' );

		if ( 0 < $max_execution_time ) {
			return time() + max( 1, $max_execution_time - self::SOFT_DEADLINE_BUFFER );
		}

		return time() + self::FALLBACK_DEADLINE;
	}

	/**
	 * Calculates exponential retry backoff.
	 *
	 * @param int $attempt Attempt number being recorded.
	 * @return int Delay in seconds.
	 */
	private function retry_delay_seconds( $attempt ) {
		$attempt = max( 1, absint( $attempt ) );

		return min( DAY_IN_SECONDS, 5 * MINUTE_IN_SECONDS * ( 2 ** ( $attempt - 1 ) ) );
	}

	/**
	 * Returns attachment paths that no longer exist.
	 *
	 * @param mixed $attachments Attachment list.
	 * @return string[]
	 */
	private function missing_attachments( $attachments ) {
		if ( ! is_array( $attachments ) ) {
			$attachments = array_filter( array_map( 'trim', explode( "\n", (string) $attachments ) ) );
		}

		$missing = array();

		foreach ( $attachments as $attachment ) {
			$path = is_string( $attachment ) ? $attachment : '';

			if ( '' !== $path && ! file_exists( $path ) ) {
				$missing[] = $path;
			}
		}

		return $missing;
	}
}
