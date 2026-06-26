<?php
/**
 * Delivery result value object.
 *
 * @package Monte_Mail_Queue_Throttle
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures the outcome of one provider delivery attempt.
 */
class Monte_Mail_Queue_Delivery_Result {
	/**
	 * Delivery status.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Error message for retry and failed outcomes.
	 *
	 * @var string
	 */
	private $error;

	/**
	 * Delay before retrying, in seconds.
	 *
	 * @var int
	 */
	private $retry_after_seconds;

	/**
	 * Provider feedback identifier.
	 *
	 * @var string
	 */
	private $provider_message_id;

	/**
	 * Provider response code.
	 *
	 * @var int
	 */
	private $response_code;

	/**
	 * Constructor.
	 *
	 * @param string $status Result status.
	 * @param string $error Error message.
	 * @param int    $retry_after_seconds Retry delay.
	 * @param string $provider_message_id Provider identifier.
	 * @param int    $response_code Response code.
	 */
	private function __construct( $status, $error, $retry_after_seconds, $provider_message_id, $response_code ) {
		$this->status              = (string) $status;
		$this->error               = (string) $error;
		$this->retry_after_seconds = max( 0, absint( $retry_after_seconds ) );
		$this->provider_message_id = (string) $provider_message_id;
		$this->response_code       = absint( $response_code );
	}

	/**
	 * Returns an accepted delivery result.
	 *
	 * @param string $provider_message_id Provider feedback identifier.
	 * @param int    $code Provider response code.
	 * @return self
	 */
	public static function accepted_result( $provider_message_id = '', $code = 0 ) {
		return new self( 'accepted', '', 0, $provider_message_id, $code );
	}

	/**
	 * Returns a retryable delivery result.
	 *
	 * @param string $error Error message.
	 * @param int    $retry_after_seconds Retry delay.
	 * @param int    $code Provider response code.
	 * @return self
	 */
	public static function retry_result( $error, $retry_after_seconds = 0, $code = 0 ) {
		return new self( 'retry', $error, $retry_after_seconds, '', $code );
	}

	/**
	 * Returns a failed delivery result.
	 *
	 * @param string $error Error message.
	 * @param int    $code Provider response code.
	 * @return self
	 */
	public static function failed_result( $error, $code = 0 ) {
		return new self( 'failed', $error, 0, '', $code );
	}

	/**
	 * Returns whether the provider accepted the send.
	 *
	 * @return bool
	 */
	public function accepted() {
		return 'accepted' === $this->status;
	}

	/**
	 * Returns whether the outcome should be retried.
	 *
	 * @return bool
	 */
	public function retryable() {
		return 'retry' === $this->status;
	}

	/**
	 * Returns whether the outcome is a final failure.
	 *
	 * @return bool
	 */
	public function failed() {
		return 'failed' === $this->status;
	}

	/**
	 * Returns the error message.
	 *
	 * @return string
	 */
	public function error() {
		return $this->error;
	}

	/**
	 * Returns the retry delay in seconds.
	 *
	 * @return int
	 */
	public function retry_after_seconds() {
		return $this->retry_after_seconds;
	}

	/**
	 * Returns the provider feedback identifier.
	 *
	 * @return string
	 */
	public function provider_message_id() {
		return $this->provider_message_id;
	}

	/**
	 * Returns the provider response code.
	 *
	 * @return int
	 */
	public function response_code() {
		return $this->response_code;
	}
}
