<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-settings.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-repository.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-source-detector.php';
if ( ! class_exists( 'Monte_Mail_Queue_Interceptor' ) ) {
	require_once __DIR__ . '/../includes/class-monte-mail-queue-interceptor.php';
}
require_once __DIR__ . '/../includes/class-monte-mail-queue-delivery-result.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-worker.php';

class Wmqt_Fake_Worker_Repository extends Monte_Mail_Queue_Repository {
	public $items = array();
	public $claimed = 0;
	public $sent = array();
	public $retried = array();
	public $logs = array();
	public $lock_token = 'worker-lock-token';
	public $lock_attempts = 0;
	public $released_locks = array();

	public function recover_stale_processing_items(): void {}
	public function purge_old_logs(): int { return 0; }
	public function purge_old_queue_items(): int { return 0; }
	public function acquire_worker_lock(): string { $this->lock_attempts++; return $this->lock_token; }
	public function release_worker_lock( string $token ): bool { $this->released_locks[] = $token; return true; }
	public function claim_batch( int $limit ): array {
		$this->claimed += $limit;
		return empty( $this->items ) ? array() : array( array_shift( $this->items ) );
	}
	public function mark_sent( int $id ): bool { $this->sent[] = $id; return true; }
	public function mark_retry( int $id, string $error, int $delay_seconds ): bool { $this->retried[] = array( $id, $error, $delay_seconds ); return true; }
	public function log( int $queue_id, string $event_type, string $message, string $source_plugin = '' ): void { $this->logs[] = array( $queue_id, $event_type, $message, $source_plugin ); }
}

class Wmqt_Fake_Throttle_Window {
	public $status;
	public $recorded = array();
	public function __construct( $status ) { $this->status = $status; }
	public function status( $transport ) { return $this->status; }
	public function record_accepted( $queue_id, $transport, $provider_message_id = '' ) { $this->recorded[] = array( $queue_id, $transport, $provider_message_id ); }
	public function prune() {}
}

class Wmqt_Fake_Azure_Client {
	public $result;
	public $sent_mail = array();
	public function __construct( $result ) { $this->result = $result; }
	public function send( array $mail, array $overrides = array() ) { $this->sent_mail[] = $mail; return $this->result; }
}

wmqt_test( 'worker stops before claiming when minute throttle is full', function () {
	wmqt_reset_test_state();
	$settings = new Monte_Mail_Queue_Settings();
	$repo = new Wmqt_Fake_Worker_Repository( $settings );
	$throttle = new Wmqt_Fake_Throttle_Window( array( 'allowed' => false, 'reason' => 'minute', 'minute_used' => 25, 'hour_used' => 100, 'minute_limit' => 25, 'hour_limit' => 1500 ) );
	$worker = new Monte_Mail_Queue_Worker( $settings, $repo, new Monte_Mail_Queue_Interceptor( $settings, $repo, new Monte_Mail_Queue_Source_Detector() ), $throttle, new Wmqt_Fake_Azure_Client( Monte_Mail_Queue_Delivery_Result::accepted_result( 'op-1', 202 ) ) );

	$worker->process_queue();

	wmqt_assert_same( 0, $repo->claimed, 'claim count' );
	wmqt_assert_same( 'throttled_minute', $repo->logs[0][1], 'log event' );
	wmqt_assert_same( array( 'worker-lock-token' ), $repo->released_locks, 'released worker lock' );
} );

wmqt_test( 'worker skips processing when another worker holds the lock', function () {
	wmqt_reset_test_state();
	$settings = new Monte_Mail_Queue_Settings();
	$settings->update( array( 'azure_email_enabled' => 1, 'worker_interval_minutes' => 1 ) );
	$repo = new Wmqt_Fake_Worker_Repository( $settings );
	$repo->lock_token = '';
	$repo->items = array( array( 'id' => 11, 'to' => 'user@example.com', 'subject' => 'Subject', 'message' => 'Body', 'attachments' => array(), 'attempts' => 0, 'max_attempts' => 3, 'source_plugin' => '' ) );
	$throttle = new Wmqt_Fake_Throttle_Window( array( 'allowed' => true, 'reason' => '', 'minute_used' => 0, 'hour_used' => 0, 'minute_limit' => 25, 'hour_limit' => 1500 ) );
	$azure = new Wmqt_Fake_Azure_Client( Monte_Mail_Queue_Delivery_Result::accepted_result( 'op-locked', 202 ) );
	$worker = new Monte_Mail_Queue_Worker( $settings, $repo, new Monte_Mail_Queue_Interceptor( $settings, $repo, new Monte_Mail_Queue_Source_Detector() ), $throttle, $azure );

	$worker->process_queue();

	wmqt_assert_same( 1, $repo->lock_attempts, 'lock attempted' );
	wmqt_assert_same( 0, $repo->claimed, 'claim count' );
	wmqt_assert_same( array(), $repo->sent, 'sent items' );
	wmqt_assert_same( 'worker_locked', $repo->logs[0][1], 'lock log event' );
	wmqt_assert_same( array(), $repo->released_locks, 'no lock release without ownership' );
} );

wmqt_test( 'worker records azure accepted send', function () {
	wmqt_reset_test_state();
	$settings = new Monte_Mail_Queue_Settings();
	$settings->update( array( 'azure_email_enabled' => 1, 'worker_interval_minutes' => 1 ) );
	$repo = new Wmqt_Fake_Worker_Repository( $settings );
	$repo->items = array( array( 'id' => 7, 'to' => 'user@example.com', 'subject' => 'Subject', 'message' => 'Body', 'attachments' => array(), 'attempts' => 0, 'max_attempts' => 3, 'source_plugin' => '' ) );
	$throttle = new Wmqt_Fake_Throttle_Window( array( 'allowed' => true, 'reason' => '', 'minute_used' => 0, 'hour_used' => 0, 'minute_limit' => 25, 'hour_limit' => 1500 ) );
	$azure = new Wmqt_Fake_Azure_Client( Monte_Mail_Queue_Delivery_Result::accepted_result( 'op-123', 202 ) );
	$worker = new Monte_Mail_Queue_Worker( $settings, $repo, new Monte_Mail_Queue_Interceptor( $settings, $repo, new Monte_Mail_Queue_Source_Detector() ), $throttle, $azure );

	$worker->process_queue();

	wmqt_assert_same( array( 7 ), $repo->sent, 'sent item' );
	wmqt_assert_same( array( array( 7, 'azure_communication_email', 'op-123' ) ), $throttle->recorded, 'accepted window' );
	wmqt_assert_same( 'azure_send_accepted', $repo->logs[0][1], 'provider log' );
	wmqt_assert_same( array( 'worker-lock-token' ), $repo->released_locks, 'released worker lock' );
} );

wmqt_test( 'worker uses azure retry delay when provider throttles', function () {
	wmqt_reset_test_state();
	$settings = new Monte_Mail_Queue_Settings();
	$settings->update( array( 'azure_email_enabled' => 1, 'worker_interval_minutes' => 1 ) );
	$repo = new Wmqt_Fake_Worker_Repository( $settings );
	$repo->items = array( array( 'id' => 8, 'to' => 'user@example.com', 'subject' => 'Subject', 'message' => 'Body', 'attachments' => array(), 'attempts' => 0, 'max_attempts' => 3, 'source_plugin' => '' ) );
	$throttle = new Wmqt_Fake_Throttle_Window( array( 'allowed' => true, 'reason' => '', 'minute_used' => 0, 'hour_used' => 0, 'minute_limit' => 25, 'hour_limit' => 1500 ) );
	$azure = new Wmqt_Fake_Azure_Client( Monte_Mail_Queue_Delivery_Result::retry_result( 'Too many requests', 120, 429 ) );
	$worker = new Monte_Mail_Queue_Worker( $settings, $repo, new Monte_Mail_Queue_Interceptor( $settings, $repo, new Monte_Mail_Queue_Source_Detector() ), $throttle, $azure );

	$worker->process_queue();

	wmqt_assert_same( array( array( 8, 'Too many requests', 120 ) ), $repo->retried, 'retry delay' );
} );
