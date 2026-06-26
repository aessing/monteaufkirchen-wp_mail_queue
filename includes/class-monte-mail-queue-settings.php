<?php
/**
 * Settings persistence for Monte Mail Queue Throttle.
 *
 * @package Monte_Mail_Queue_Throttle
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and sanitizes plugin settings.
 */
class Monte_Mail_Queue_Settings {
	/**
	 * Default plugin settings.
	 *
	 * @var array<string, mixed>
	 */
	private $defaults = array(
		'rate_per_minute'      => 25,
		'rate_per_hour'        => 1500,
		'worker_interval_minutes' => 2,
		'max_attempts'         => 3,
		'queue_mode'           => 'all',
		'allowed_plugins'      => 'email-users,send-users-email',
		'log_retention_days'   => 30,
		'queue_retention_days' => 180,
		'azure_email_enabled'  => 0,
		'azure_connection_string' => '',
		'azure_sender_domains' => '',
		'azure_sender_username' => 'DoNotReply',
		'azure_default_domain' => '',
		'azure_reply_to'       => '',
	);

	/**
	 * Option name used for persistence.
	 *
	 * @var string
	 */
	private $option_name;

	/**
	 * Constructor.
	 *
	 * @param string $option_name Option name.
	 */
	public function __construct( $option_name = WMQT_OPTION_NAME ) {
		$this->option_name = $option_name;
	}

	/**
	 * Returns all settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function get_all() {
		$stored = get_option( $this->option_name, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return $this->sanitize( array_merge( $this->defaults, $stored ) );
	}

	/**
	 * Returns one setting by key.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Default value when key is absent.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$settings = $this->get_all();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Sanitizes and persists settings.
	 *
	 * @param array<string, mixed> $settings Settings to persist.
	 * @return bool True when the option value changed.
	 */
	public function update( array $settings ) {
		$current = $this->get_all();
		$next    = $this->sanitize( array_merge( $current, $settings ) );

		return update_option( $this->option_name, $next );
	}

	/**
	 * Returns default settings.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults() {
		return $this->defaults;
	}

	/**
	 * Sanitizes a settings array.
	 *
	 * @param array<string, mixed> $settings Settings to sanitize.
	 * @return array<string, mixed>
	 */
	private function sanitize( array $settings ) {
		$queue_mode = isset( $settings['queue_mode'] ) ? sanitize_key( $settings['queue_mode'] ) : $this->defaults['queue_mode'];

		if ( ! in_array( $queue_mode, array( 'all', 'selected' ), true ) ) {
			$queue_mode = $this->defaults['queue_mode'];
		}

		return array(
			'rate_per_minute'      => max( 1, absint( $settings['rate_per_minute'] ?? $this->defaults['rate_per_minute'] ) ),
			'rate_per_hour'        => max( 1, absint( $settings['rate_per_hour'] ?? $this->defaults['rate_per_hour'] ) ),
			'worker_interval_minutes' => min( 60, max( 1, absint( $settings['worker_interval_minutes'] ?? $this->defaults['worker_interval_minutes'] ) ) ),
			'max_attempts'         => max( 1, absint( $settings['max_attempts'] ?? $this->defaults['max_attempts'] ) ),
			'queue_mode'           => $queue_mode,
			'allowed_plugins'      => $this->sanitize_allowed_plugins( $settings['allowed_plugins'] ?? $this->defaults['allowed_plugins'] ),
			'log_retention_days'   => max( 1, absint( $settings['log_retention_days'] ?? $this->defaults['log_retention_days'] ) ),
			'queue_retention_days' => max( 1, absint( $settings['queue_retention_days'] ?? $this->defaults['queue_retention_days'] ) ),
			'azure_email_enabled'  => empty( $settings['azure_email_enabled'] ) ? 0 : 1,
			'azure_connection_string' => $this->sanitize_connection_string( $settings['azure_connection_string'] ?? '' ),
			'azure_sender_domains' => $this->sanitize_sender_domains( $settings['azure_sender_domains'] ?? '' ),
			'azure_sender_username' => $this->sanitize_sender_username( $settings['azure_sender_username'] ?? $this->defaults['azure_sender_username'] ),
			'azure_default_domain' => $this->sanitize_domain( $settings['azure_default_domain'] ?? '' ),
			'azure_reply_to'       => sanitize_email( $settings['azure_reply_to'] ?? '' ),
		);
	}

	/**
	 * Sanitizes a comma-separated list of plugin slugs.
	 *
	 * @param mixed $value Raw allowed plugin value.
	 * @return string
	 */
	private function sanitize_allowed_plugins( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( ',', $value );
		}

		$slugs = array_filter(
			array_map(
				static function ( $slug ) {
					return sanitize_title( trim( $slug ) );
				},
				explode( ',', (string) $value )
			)
		);

		return implode( ',', array_unique( $slugs ) );
	}

	/**
	 * Sanitizes the Azure connection string.
	 *
	 * @param mixed $value Raw connection string.
	 * @return string
	 */
	private function sanitize_connection_string( $value ) {
		return trim( preg_replace( '/[\r\n\t]+/', '', (string) $value ) );
	}

	/**
	 * Sanitizes a comma or newline-separated list of sender domains.
	 *
	 * @param mixed $value Raw domain list.
	 * @return string
	 */
	private function sanitize_sender_domains( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( ',', $value );
		}

		$domains = preg_split( '/[\r\n,]+/', (string) $value );
		$domains = array_filter( array_map( array( $this, 'sanitize_domain' ), $domains ) );

		return implode( ',', array_unique( $domains ) );
	}

	/**
	 * Sanitizes the Azure sender username.
	 *
	 * @param mixed $value Raw sender username.
	 * @return string
	 */
	private function sanitize_sender_username( $value ) {
		$value = preg_replace( '/[^A-Za-z0-9._%+\-]/', '', (string) $value );

		return '' !== $value ? $value : $this->defaults['azure_sender_username'];
	}

	/**
	 * Sanitizes a domain-like value.
	 *
	 * @param mixed $value Raw domain.
	 * @return string
	 */
	private function sanitize_domain( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9.\-]/', '', $value );

		return trim( $value, '.-' );
	}
}
