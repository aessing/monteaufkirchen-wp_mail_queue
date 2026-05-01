<?php
/**
 * Cron worker for queued wp_mail() payloads.
 *
 * @package WP_Mail_Queue_Throttle
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes queued mail in throttled batches.
 */
class WP_Mail_Queue_Worker {
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
	 * Interceptor dependency.
	 *
	 * @var WP_Mail_Queue_Interceptor
	 */
	private $interceptor;

	/**
	 * Constructor.
	 *
	 * @param WP_Mail_Queue_Settings    $settings Settings dependency.
	 * @param WP_Mail_Queue_Repository  $repository Repository dependency.
	 * @param WP_Mail_Queue_Interceptor $interceptor Interceptor dependency.
	 */
	public function __construct(
		WP_Mail_Queue_Settings $settings,
		WP_Mail_Queue_Repository $repository,
		WP_Mail_Queue_Interceptor $interceptor
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
		$limit = max( 1, absint( $this->settings->get( 'rate_per_minute', 25 ) ) * 2 );
		$items = $this->repository->claim_batch( $limit );

		foreach ( $items as $item ) {
			$this->process_item( $item );
		}
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
				$this->repository->mark_sent( $id );
				$this->repository->log( $id, 'sent', 'Mail sent successfully.', $source_plugin );
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
			$this->repository->mark_failed( $id, $error );
			$this->repository->log( $id, 'failed', $error, $source_plugin );
			return;
		}

		$this->repository->mark_retry( $id, $error );
		$this->repository->log( $id, 'retry', $error, $source_plugin );
	}
}
