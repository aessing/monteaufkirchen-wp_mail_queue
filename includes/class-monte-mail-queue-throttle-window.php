<?php
/**
 * Rolling send-window throttle decisions.
 *
 * @package Monte_Mail_Queue_Throttle
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evaluates rolling minute and hour send limits.
 */
class Monte_Mail_Queue_Throttle_Window {
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
	 * Constructor.
	 *
	 * @param Monte_Mail_Queue_Settings   $settings Settings dependency.
	 * @param Monte_Mail_Queue_Repository $repository Repository dependency.
	 */
	public function __construct( Monte_Mail_Queue_Settings $settings, Monte_Mail_Queue_Repository $repository ) {
		$this->settings   = $settings;
		$this->repository = $repository;
	}

	/**
	 * Returns the current send decision for a transport.
	 *
	 * @param string $transport Transport slug.
	 * @return array<string, int|bool|string>
	 */
	public function status( string $transport ): array {
		$transport    = sanitize_key( $transport );
		$usage        = $this->repository->send_window_usage( $transport );
		$minute_limit = max( 1, absint( $this->settings->get( 'rate_per_minute', 25 ) ) );
		$hour_limit   = max( 1, absint( $this->settings->get( 'rate_per_hour', 1500 ) ) );
		$minute_used  = isset( $usage['minute'] ) ? (int) $usage['minute'] : 0;
		$hour_used    = isset( $usage['hour'] ) ? (int) $usage['hour'] : 0;

		if ( $minute_used >= $minute_limit ) {
			return $this->decision( false, 'minute', $minute_used, $hour_used, $minute_limit, $hour_limit );
		}

		if ( $hour_used >= $hour_limit ) {
			return $this->decision( false, 'hour', $minute_used, $hour_used, $minute_limit, $hour_limit );
		}

		return $this->decision( true, '', $minute_used, $hour_used, $minute_limit, $hour_limit );
	}

	/**
	 * Records an accepted send in the rolling window table.
	 *
	 * @param int    $queue_id Queue item ID.
	 * @param string $transport Transport slug.
	 * @param string $provider_message_id Provider message identifier.
	 * @return void
	 */
	public function record_accepted( int $queue_id, string $transport, string $provider_message_id = '' ) {
		$this->repository->record_send_window( absint( $queue_id ), sanitize_key( $transport ), (string) $provider_message_id );
	}

	/**
	 * Removes old rolling-window rows.
	 *
	 * @return void
	 */
	public function prune() {
		$this->repository->purge_old_send_windows( 48 );
	}

	/**
	 * Builds a normalized throttle decision payload.
	 *
	 * @param bool   $allowed Whether sending is allowed.
	 * @param string $reason Block reason.
	 * @param int    $minute_used Used count in the rolling minute window.
	 * @param int    $hour_used Used count in the rolling hour window.
	 * @param int    $minute_limit Configured minute limit.
	 * @param int    $hour_limit Configured hour limit.
	 * @return array<string, int|bool|string>
	 */
	private function decision( $allowed, $reason, $minute_used, $hour_used, $minute_limit, $hour_limit ) {
		return array(
			'allowed'      => (bool) $allowed,
			'reason'       => (string) $reason,
			'minute_used'  => (int) $minute_used,
			'hour_used'    => (int) $hour_used,
			'minute_limit' => (int) $minute_limit,
			'hour_limit'   => (int) $hour_limit,
		);
	}
}
