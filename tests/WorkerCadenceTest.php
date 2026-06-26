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

require_once __DIR__ . '/../includes/class-monte-mail-queue-worker.php';

class WMQT_Worker_Test_Repository extends Monte_Mail_Queue_Repository {
	public $claimed_limits = array();
	public $recovered      = 0;
	public $purged_logs    = 0;
	public $purged_queue   = 0;

	public function recover_stale_processing_items() {
		$this->recovered++;
	}

	public function claim_batch( int $limit ): array {
		$this->claimed_limits[] = $limit;

		return array();
	}

	public function purge_old_logs() {
		$this->purged_logs++;
	}

	public function purge_old_queue_items() {
		$this->purged_queue++;
	}
}

wmqt_test( 'worker claims batches using configured worker interval', function () {
	wmqt_reset_test_runtime();

	$settings = new Monte_Mail_Queue_Settings();
	$settings->update(
		array(
			'rate_per_minute'        => 4,
			'worker_interval_minutes' => 7,
		)
	);

	$repository = new WMQT_Worker_Test_Repository();
	$worker     = new Monte_Mail_Queue_Worker( $settings, $repository, new Monte_Mail_Queue_Interceptor() );

	$worker->process_queue();

	wmqt_assert_same( array( 28 ), $repository->claimed_limits, 'claim batch limit uses worker interval' );
	wmqt_assert_same( 1, $repository->recovered, 'stale items recovered' );
	wmqt_assert_same( 1, $repository->purged_logs, 'logs purged' );
	wmqt_assert_same( 1, $repository->purged_queue, 'queue purged' );
} );
