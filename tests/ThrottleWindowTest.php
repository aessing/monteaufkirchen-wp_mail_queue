<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-settings.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-throttle-window.php';

class Wmqt_Fake_Window_Repository extends Monte_Mail_Queue_Repository {
	public $usage = array( 'minute' => 0, 'hour' => 0 );
	public $recorded = array();
	public $purged_hours = 0;

	public function send_window_usage( string $transport ): array {
		return $this->usage;
	}

	public function record_send_window( int $queue_id, string $transport, string $provider_message_id = '' ): bool {
		$this->recorded[] = array( $queue_id, $transport, $provider_message_id );
		return true;
	}

	public function purge_old_send_windows( int $hours = 48 ): int {
		$this->purged_hours = $hours;
		return 0;
	}
}

wmqt_test( 'throttle allows send below minute and hour limits', function () {
	wmqt_reset_test_state();
	$settings = new Monte_Mail_Queue_Settings();
	$settings->update( array( 'rate_per_minute' => 25, 'rate_per_hour' => 1500 ) );
	$repo = new Wmqt_Fake_Window_Repository( $settings );
	$repo->usage = array( 'minute' => 24, 'hour' => 1499 );

	$status = ( new Monte_Mail_Queue_Throttle_Window( $settings, $repo ) )->status( 'wp_mail' );

	wmqt_assert_same( true, $status['allowed'], 'allowed' );
	wmqt_assert_same( '', $status['reason'], 'reason' );
	wmqt_assert_same( 24, $status['minute_used'], 'minute used' );
	wmqt_assert_same( 1499, $status['hour_used'], 'hour used' );
} );

wmqt_test( 'throttle blocks when minute limit is reached', function () {
	wmqt_reset_test_state();
	$settings = new Monte_Mail_Queue_Settings();
	$settings->update( array( 'rate_per_minute' => 25, 'rate_per_hour' => 1500 ) );
	$repo = new Wmqt_Fake_Window_Repository( $settings );
	$repo->usage = array( 'minute' => 25, 'hour' => 100 );

	$status = ( new Monte_Mail_Queue_Throttle_Window( $settings, $repo ) )->status( 'wp_mail' );

	wmqt_assert_same( false, $status['allowed'], 'blocked' );
	wmqt_assert_same( 'minute', $status['reason'], 'reason' );
} );

wmqt_test( 'throttle blocks when hour limit is reached', function () {
	wmqt_reset_test_state();
	$settings = new Monte_Mail_Queue_Settings();
	$settings->update( array( 'rate_per_minute' => 25, 'rate_per_hour' => 100 ) );
	$repo = new Wmqt_Fake_Window_Repository( $settings );
	$repo->usage = array( 'minute' => 1, 'hour' => 100 );

	$status = ( new Monte_Mail_Queue_Throttle_Window( $settings, $repo ) )->status( 'azure_communication_email' );

	wmqt_assert_same( false, $status['allowed'], 'blocked' );
	wmqt_assert_same( 'hour', $status['reason'], 'reason' );
} );

wmqt_test( 'throttle records accepted sends and prunes forty eight hours', function () {
	wmqt_reset_test_state();
	$settings = new Monte_Mail_Queue_Settings();
	$repo     = new Wmqt_Fake_Window_Repository( $settings );
	$window   = new Monte_Mail_Queue_Throttle_Window( $settings, $repo );

	$window->record_accepted( 123, 'azure_communication_email', 'op-1' );
	$window->prune();

	wmqt_assert_same( array( array( 123, 'azure_communication_email', 'op-1' ) ), $repo->recorded, 'recorded send' );
	wmqt_assert_same( 48, $repo->purged_hours, 'prune window' );
} );
