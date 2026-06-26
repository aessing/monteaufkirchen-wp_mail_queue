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
	 * Constructor.
	 *
	 * @param Monte_Mail_Queue_Settings    $settings Settings dependency.
	 * @param Monte_Mail_Queue_Repository  $repository Repository dependency.
	 * @param Monte_Mail_Queue_Interceptor $interceptor Interceptor dependency.
	 * @param mixed                        $throttle_window Throttle window dependency.
	 * @param mixed                        $azure_client Azure transport dependency.
	 */
	public function __construct(
		Monte_Mail_Queue_Settings $settings,
		Monte_Mail_Queue_Repository $repository,
		Monte_Mail_Queue_Interceptor $interceptor,
		$throttle_window = null,
		$azure_client = null
	) {
		$this->settings        = $settings;
		$this->repository      = $repository;
		$this->interceptor     = $interceptor;
		$this->throttle_window = $throttle_window;
		$this->azure_client    = $azure_client;
	}

	/**
	 * Processes one cron batch.
	 *
	 * @return void
	 */
	public function process_queue() {
		$lock_token = $this->acquire_lock();

		if ( '' === $lock_token ) {
			$this->repository->log( 0, 'worker_locked', 'Worker skipped because another queue worker is already running.', '' );
			return;
		}

		try {
			$this->process_locked_queue();
		} finally {
			$this->release_lock( $lock_token );
		}
	}

	/**
	 * Processes one cron batch after the worker lock is held.
	 *
	 * @return void
	 */
	private function process_locked_queue() {
		$limit    = max( 1, absint( $this->settings->get( 'rate_per_minute', 25 ) ) * absint( $this->settings->get( 'worker_interval_minutes', 2 ) ) );
		$deadline = $this->deadline_timestamp();
		$sent     = 0;

		$this->repository->recover_stale_processing_items();

		while ( $sent < $limit && time() < $deadline ) {
			$transport = $this->transport();
			$status    = $this->throttle_window ? $this->throttle_window->status( $transport ) : array( 'allowed' => true );

			if ( empty( $status['allowed'] ) ) {
				$this->repository->log( 0, 'minute' === $status['reason'] ? 'throttled_minute' : 'throttled_hour', $this->throttle_message( $status ), '' );
				break;
			}

			$items = $this->repository->claim_batch( 1 );

			if ( empty( $items ) ) {
				break;
			}

			$this->process_item( $items[0], $transport );
			$sent++;
		}

		if ( $this->throttle_window && method_exists( $this->throttle_window, 'prune' ) ) {
			$this->throttle_window->prune();
		}

		$this->repository->purge_old_logs();
		$this->repository->purge_old_queue_items();
	}

	/**
	 * Acquires a worker lock when the repository supports it.
	 *
	 * @return string
	 */
	private function acquire_lock() {
		if ( method_exists( $this->repository, 'acquire_worker_lock' ) ) {
			return (string) $this->repository->acquire_worker_lock();
		}

		return 'legacy-repository-lock';
	}

	/**
	 * Releases a worker lock when the repository supports it.
	 *
	 * @param string $lock_token Lock token.
	 * @return void
	 */
	private function release_lock( $lock_token ) {
		if ( 'legacy-repository-lock' === $lock_token ) {
			return;
		}

		if ( method_exists( $this->repository, 'release_worker_lock' ) ) {
			$this->repository->release_worker_lock( $lock_token );
		}
	}

	/**
	 * Replays one queued mail payload.
	 *
	 * @param array<string, mixed> $item Queue item.
	 * @param string              $transport Transport slug.
	 * @return void
	 */
	private function process_item( array $item, $transport ) {
		$id            = (int) ( $item['id'] ?? 0 );
		$source_plugin = isset( $item['source_plugin'] ) ? sanitize_key( (string) $item['source_plugin'] ) : '';
		$missing       = $this->missing_attachments( $item['attachments'] ?? array() );

		if ( ! empty( $missing ) ) {
			$this->repository->log( $id, 'attachment_missing', 'Attachment path no longer exists: ' . implode( ', ', $missing ), $source_plugin );
		}

		try {
			$result = $this->deliver_item( $item, $transport );

			if ( $result->accepted() ) {
				if ( $this->throttle_window ) {
					$this->throttle_window->record_accepted( $id, $transport, $result->provider_message_id() );
				}

				if ( 'azure_communication_email' === $transport && '' !== $result->provider_message_id() ) {
					$this->repository->log( $id, 'azure_send_accepted', 'Azure accepted send operation: ' . $result->provider_message_id(), $source_plugin );
				}

				if ( $this->repository->mark_sent( $id ) ) {
					$this->repository->log( $id, 'sent', 'Mail sent successfully.', $source_plugin );
				}
				return;
			}

			if ( $result->retryable() ) {
				$this->record_failure( $item, $result->error(), $result->retry_after_seconds() );
				return;
			}

			$this->record_failure( $item, $result->error() );
		} catch ( Throwable $throwable ) {
			$this->record_failure( $item, $throwable->getMessage() );
		}
	}

	/**
	 * Records a retryable or final failure.
	 *
	 * @param array<string, mixed> $item Queue item.
	 * @param string               $error Error message.
	 * @param int                  $delay_seconds Delay before next retry.
	 * @return void
	 */
	private function record_failure( array $item, $error, $delay_seconds = 0 ) {
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

		$delay_seconds = absint( $delay_seconds );
		$delay_seconds = 0 < $delay_seconds ? $delay_seconds : $this->retry_delay_seconds( $attempts + 1 );

		if ( $this->repository->mark_retry( $id, $error, $delay_seconds ) ) {
			$this->repository->log( $id, 'retry', $error, $source_plugin );
		}
	}

	/**
	 * Returns the active worker transport.
	 *
	 * @return string
	 */
	private function transport() {
		return 1 === (int) $this->settings->get( 'azure_email_enabled', 0 ) ? 'azure_communication_email' : 'wp_mail';
	}

	/**
	 * Delivers one queue item through the selected transport.
	 *
	 * @param array<string, mixed> $item Queue item.
	 * @param string               $transport Transport slug.
	 * @return Monte_Mail_Queue_Delivery_Result
	 */
	private function deliver_item( array $item, $transport ) {
		if ( 'azure_communication_email' === $transport ) {
			if ( $this->azure_client && method_exists( $this->azure_client, 'send' ) ) {
				return $this->azure_client->send( $item );
			}

			return Monte_Mail_Queue_Delivery_Result::retry_result( 'Azure email client is not configured.' );
		}

		$this->interceptor->enable_bypass();

		try {
			$sent = wp_mail(
				$item['to'] ?? '',
				(string) ( $item['subject'] ?? '' ),
				(string) ( $item['message'] ?? '' ),
				$item['headers'] ?? '',
				$item['attachments'] ?? array()
			);

			return true === $sent ? Monte_Mail_Queue_Delivery_Result::accepted_result( '', 0 ) : Monte_Mail_Queue_Delivery_Result::failed_result( 'wp_mail returned false.' );
		} finally {
			$this->interceptor->disable_bypass();
		}
	}

	/**
	 * Builds a throttle log message from window status.
	 *
	 * @param array<string, mixed> $status Throttle status.
	 * @return string
	 */
	private function throttle_message( array $status ) {
		$reason       = 'hour' === ( $status['reason'] ?? '' ) ? 'hour' : 'minute';
		$minute_used  = isset( $status['minute_used'] ) ? (int) $status['minute_used'] : 0;
		$hour_used    = isset( $status['hour_used'] ) ? (int) $status['hour_used'] : 0;
		$minute_limit = isset( $status['minute_limit'] ) ? (int) $status['minute_limit'] : 0;
		$hour_limit   = isset( $status['hour_limit'] ) ? (int) $status['hour_limit'] : 0;

		return sprintf(
			'Worker throttled by %1$s limit. Minute usage %2$d/%3$d, hour usage %4$d/%5$d.',
			$reason,
			$minute_used,
			$minute_limit,
			$hour_used,
			$hour_limit
		);
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
