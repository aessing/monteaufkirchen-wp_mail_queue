<?php
require_once __DIR__ . '/bootstrap.php';

if ( ! class_exists( 'Monte_Mail_Queue_Interceptor' ) ) {
	class Monte_Mail_Queue_Interceptor {
		public function enable_bypass() {
		}

		public function disable_bypass() {
		}
	}
}

if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
		return true;
	}
}

require_once __DIR__ . '/../includes/class-monte-mail-queue-worker.php';

class WMQT_Worker_Test_Repository extends Monte_Mail_Queue_Repository {
	public $claimed_limits = array();
	public $recovered      = 0;
	public $purged_logs    = 0;
	public $purged_queue   = 0;
	public $items          = array();
	public $sent_ids       = array();
	public $logs           = array();

	public function __construct( array $items = array() ) {
		$this->items = $items;
	}

	public function recover_stale_processing_items() {
		$this->recovered++;
	}

	public function claim_batch( int $limit ): array {
		$this->claimed_limits[] = $limit;
		$claimed              = array();

		foreach ( $this->items as $index => $item ) {
			if ( 'queued' !== $item['status'] ) {
				continue;
			}

			$this->items[ $index ]['status'] = 'processing';
			$item['status']                  = 'processing';
			$claimed[]                       = $item;

			if ( count( $claimed ) >= $limit ) {
				break;
			}
		}

		return $claimed;
	}

	public function mark_sent( int $id ): bool {
		foreach ( $this->items as $index => $item ) {
			if ( $id !== (int) $item['id'] ) {
				continue;
			}

			if ( 'processing' !== $item['status'] ) {
				return false;
			}

			$this->items[ $index ]['status'] = 'sent';
			$this->sent_ids[]                = $id;
			return true;
		}

		return false;
	}

	public function mark_retry( int $id, string $error, int $delay_seconds ): bool {
		return false;
	}

	public function mark_failed( int $id, string $error ): bool {
		return false;
	}

	public function log( int $queue_id, string $event_type, string $message, string $source_plugin = '' ): void {
		$this->logs[] = array(
			'queue_id'   => $queue_id,
			'event_type' => $event_type,
			'message'    => $message,
		);
	}

	public function purge_old_logs() {
		$this->purged_logs++;
	}

	public function purge_old_queue_items() {
		$this->purged_queue++;
	}
}

wmqt_test( 'worker processes each claimed item without stranding processing rows', function () {
	wmqt_reset_test_runtime();

	$settings = new Monte_Mail_Queue_Settings();
	$settings->update(
		array(
			'rate_per_minute'         => 1,
			'worker_interval_minutes' => 2,
		)
	);

	$repository = new WMQT_Worker_Test_Repository(
		array(
			array(
				'id'           => 101,
				'status'       => 'queued',
				'attempts'     => 0,
				'max_attempts' => 3,
				'to'           => 'one@example.com',
				'subject'      => 'First',
				'message'      => 'Body',
				'headers'      => '',
				'attachments'  => array(),
			),
			array(
				'id'           => 102,
				'status'       => 'queued',
				'attempts'     => 0,
				'max_attempts' => 3,
				'to'           => 'two@example.com',
				'subject'      => 'Second',
				'message'      => 'Body',
				'headers'      => '',
				'attachments'  => array(),
			),
		)
	);
	$worker     = new Monte_Mail_Queue_Worker( $settings, $repository, new Monte_Mail_Queue_Interceptor() );

	$worker->process_queue();

	wmqt_assert_same( array( 1, 1 ), $repository->claimed_limits, 'worker claims one item per iteration' );
	wmqt_assert_same( array( 101, 102 ), $repository->sent_ids, 'worker marks each claimed item sent' );
	wmqt_assert_same( array( 'sent', 'sent' ), array_column( $repository->items, 'status' ), 'no claimed rows remain stuck in processing' );
	wmqt_assert_same( 1, $repository->recovered, 'stale items recovered' );
	wmqt_assert_same( 1, $repository->purged_logs, 'logs purged' );
	wmqt_assert_same( 1, $repository->purged_queue, 'queue purged' );
} );
