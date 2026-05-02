<?php
/**
 * Intercepts wp_mail() calls and stores them in the queue.
 *
 * @package Monte_Mail_Queue_Throttle
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queues eligible wp_mail() payloads before WordPress sends them.
 */
class WP_Mail_Queue_Interceptor {
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
	 * Source detector dependency.
	 *
	 * @var WP_Mail_Queue_Source_Detector
	 */
	private $source_detector;

	/**
	 * Whether interception is bypassed.
	 *
	 * @var bool
	 */
	private $bypassing = false;

	/**
	 * Constructor.
	 *
	 * @param WP_Mail_Queue_Settings        $settings Settings dependency.
	 * @param WP_Mail_Queue_Repository      $repository Repository dependency.
	 * @param WP_Mail_Queue_Source_Detector $source_detector Source detector dependency.
	 */
	public function __construct(
		WP_Mail_Queue_Settings $settings,
		WP_Mail_Queue_Repository $repository,
		WP_Mail_Queue_Source_Detector $source_detector
	) {
		$this->settings        = $settings;
		$this->repository      = $repository;
		$this->source_detector = $source_detector;
	}

	/**
	 * Enables bypass mode for replay sends.
	 *
	 * @return void
	 */
	public function enable_bypass() {
		$this->bypassing = true;
	}

	/**
	 * Disables bypass mode after replay sends.
	 *
	 * @return void
	 */
	public function disable_bypass() {
		$this->bypassing = false;
	}

	/**
	 * Reports whether bypass mode is active.
	 *
	 * @return bool
	 */
	public function is_bypassing() {
		return $this->bypassing;
	}

	/**
	 * Filters wp_mail() before the transport runs.
	 *
	 * @param null|bool            $pre  Existing pre_wp_mail value.
	 * @param array<string, mixed> $atts Mail attributes.
	 * @return null|bool
	 */
	public function pre_wp_mail( $pre, $atts ) {
		if ( null !== $pre ) {
			return $pre;
		}

		if ( $this->is_bypassing() ) {
			return $pre;
		}

		$mail          = $this->normalize_atts( is_array( $atts ) ? $atts : array() );
		$source_plugin = $this->detect_source_plugin();

		if ( ! $this->should_queue( $source_plugin ) ) {
			return null;
		}

		$queue_id = $this->repository->enqueue( $mail, $source_plugin );

		if ( 1 > $queue_id ) {
			$this->repository->log( 0, 'enqueue_failed', 'Mail could not be queued; continuing normal wp_mail delivery.', $source_plugin );
			return null;
		}

		$this->repository->log( $queue_id, 'queued', 'Mail queued for throttled delivery.', $source_plugin );

		return true;
	}

	/**
	 * Normalizes wp_mail() attributes for persistence.
	 *
	 * @param array<string, mixed> $atts Mail attributes.
	 * @return array<string, mixed>
	 */
	private function normalize_atts( array $atts ) {
		return array(
			'to'          => $atts['to'] ?? '',
			'subject'     => (string) ( $atts['subject'] ?? '' ),
			'message'     => (string) ( $atts['message'] ?? '' ),
			'headers'     => $atts['headers'] ?? '',
			'attachments' => $atts['attachments'] ?? array(),
		);
	}

	/**
	 * Detects the source plugin slug.
	 *
	 * @return string
	 */
	private function detect_source_plugin() {
		$source_plugin = $this->source_detector->detect();

		return is_string( $source_plugin ) ? sanitize_key( $source_plugin ) : '';
	}

	/**
	 * Determines whether a source plugin should be queued.
	 *
	 * @param string $source_plugin Source plugin slug.
	 * @return bool
	 */
	private function should_queue( $source_plugin ) {
		$queue_mode = sanitize_key( (string) $this->settings->get( 'queue_mode', 'all' ) );

		if ( 'all' === $queue_mode ) {
			return true;
		}

		if ( ! in_array( $queue_mode, array( 'selected', 'selected_plugins' ), true ) ) {
			return true;
		}

		if ( '' === $source_plugin ) {
			return false;
		}

		return in_array( $source_plugin, $this->allowed_plugins(), true );
	}

	/**
	 * Returns configured allowed plugin slugs.
	 *
	 * @return string[]
	 */
	private function allowed_plugins() {
		$allowed_plugins = $this->settings->get( 'allowed_plugins', '' );

		if ( is_array( $allowed_plugins ) ) {
			$allowed_plugins = implode( ',', $allowed_plugins );
		}

		$slugs = array_map(
			static function ( $slug ) {
				return sanitize_key( trim( $slug ) );
			},
			explode( ',', (string) $allowed_plugins )
		);

		return array_values( array_unique( array_filter( $slugs ) ) );
	}
}
