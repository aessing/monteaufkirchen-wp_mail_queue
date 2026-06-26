<?php
/**
 * Azure Communication Services Email REST client.
 *
 * @package Monte_Mail_Queue_Throttle
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends queued mail through Azure Communication Services Email.
 */
class Monte_Mail_Queue_Azure_Email_Client {
	/**
	 * Azure API version.
	 */
	const API_VERSION = '2023-03-31';

	/**
	 * Settings dependency.
	 *
	 * @var Monte_Mail_Queue_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Monte_Mail_Queue_Settings $settings Settings dependency.
	 */
	public function __construct( Monte_Mail_Queue_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Sends one mail payload through ACS Email.
	 *
	 * @param array<string, mixed> $mail Queued mail payload.
	 * @param array<string, mixed> $overrides Sender overrides.
	 * @return Monte_Mail_Queue_Delivery_Result
	 */
	public function send( array $mail, array $overrides = array() ) {
		$connection = $this->parse_connection_string( (string) $this->settings->get( 'azure_connection_string', '' ) );

		if ( empty( $connection['endpoint'] ) || empty( $connection['accesskey'] ) ) {
			return Monte_Mail_Queue_Delivery_Result::retry_result( 'Azure Communication Services connection string is invalid.' );
		}

		$sender_username = isset( $overrides['sender_username'] ) ? sanitize_text_field( $overrides['sender_username'] ) : (string) $this->settings->get( 'azure_sender_username', 'DoNotReply' );
		$sender_domain   = isset( $overrides['sender_domain'] ) ? sanitize_text_field( $overrides['sender_domain'] ) : (string) $this->settings->get( 'azure_default_domain', '' );
		$sender_address  = trim( $sender_username . '@' . $sender_domain, '@' );

		if ( '' === $sender_username || '' === $sender_domain || false === strpos( $sender_address, '@' ) ) {
			return Monte_Mail_Queue_Delivery_Result::failed_result( 'Azure sender configuration is incomplete.' );
		}

		$path_and_query = '/emails:send?api-version=' . self::API_VERSION;
		$body_array     = $this->payload( $mail, $overrides, $sender_address );
		$body           = wp_json_encode( $body_array );
		$headers        = $this->auth_headers( $connection['endpoint'], $connection['accesskey'], $path_and_query, $body );

		if ( false === $body || empty( $headers ) ) {
			return Monte_Mail_Queue_Delivery_Result::retry_result( 'Azure Communication Services connection string is invalid.' );
		}

		$response = wp_remote_post(
			$connection['endpoint'] . $path_and_query,
			array(
				'method'  => 'POST',
				'timeout' => 15,
				'headers' => $headers,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return Monte_Mail_Queue_Delivery_Result::retry_result( $response->get_error_message() );
		}

		$code         = wp_remote_retrieve_response_code( $response );
		$headers      = wp_remote_retrieve_headers( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( 202 === $code ) {
			return Monte_Mail_Queue_Delivery_Result::accepted_result( $this->operation_id_from_headers( $headers ), $code );
		}

		if ( in_array( $code, array( 429, 500, 502, 503, 504 ), true ) ) {
			$message = '' !== trim( $response_body ) ? trim( $response_body ) : 'Azure Communication Services request should be retried.';
			return Monte_Mail_Queue_Delivery_Result::retry_result( $message, $this->retry_after_seconds( $headers ), $code );
		}

		$message = '' !== trim( $response_body ) ? trim( $response_body ) : 'Azure Communication Services request failed.';

		return Monte_Mail_Queue_Delivery_Result::failed_result( $message, $code );
	}

	/**
	 * Parses an ACS connection string.
	 *
	 * @param string $connection_string Raw ACS connection string.
	 * @return array<string, string>
	 */
	public function parse_connection_string( $connection_string ) {
		$parts  = explode( ';', (string) $connection_string );
		$parsed = array(
			'endpoint'  => '',
			'accesskey' => '',
		);

		foreach ( $parts as $part ) {
			$pair = explode( '=', $part, 2 );

			if ( 2 !== count( $pair ) ) {
				continue;
			}

			$key   = strtolower( trim( $pair[0] ) );
			$value = trim( $pair[1] );

			if ( 'endpoint' === $key ) {
				$parsed['endpoint'] = rtrim( $value, '/' );
			} elseif ( 'accesskey' === $key ) {
				$parsed['accesskey'] = $value;
			}
		}

		return $parsed;
	}

	/**
	 * Builds the ACS Email request payload.
	 *
	 * @param array<string, mixed> $mail Queued mail payload.
	 * @param array<string, mixed> $overrides Sender overrides.
	 * @param string               $sender_address Resolved sender address.
	 * @return array<string, mixed>
	 */
	private function payload( array $mail, array $overrides, $sender_address ) {
		unset( $overrides );

		$message      = isset( $mail['message'] ) ? (string) $mail['message'] : '';
		$headers      = isset( $mail['headers'] ) ? $mail['headers'] : array();
		$reply_to     = (string) $this->settings->get( 'azure_reply_to', '' );
		$is_html      = $this->is_html_message( $message, $headers );
		$payload      = array(
			'senderAddress' => $sender_address,
			'recipients'    => array(
				'to' => $this->recipients( isset( $mail['to'] ) ? $mail['to'] : array() ),
			),
			'content'       => array(
				'subject' => isset( $mail['subject'] ) ? (string) $mail['subject'] : '',
			),
		);

		if ( $is_html ) {
			$payload['content']['html'] = $message;
		} else {
			$payload['content']['plainText'] = $message;
		}

		if ( '' !== $reply_to ) {
			$payload['replyTo'] = array(
				array(
					'address' => $reply_to,
				),
			);
		}

		$attachments = $this->attachments( isset( $mail['attachments'] ) ? $mail['attachments'] : array() );

		if ( ! empty( $attachments ) ) {
			$payload['attachments'] = $attachments;
		}

		return $payload;
	}

	/**
	 * Builds ACS HMAC headers.
	 *
	 * @param string $endpoint Endpoint URL.
	 * @param string $access_key Base64-encoded access key.
	 * @param string $path_and_query Request path and query.
	 * @param string $body JSON request body.
	 * @return array<string, string>
	 */
	private function auth_headers( $endpoint, $access_key, $path_and_query, $body ) {
		$decoded_key = base64_decode( (string) $access_key, true );

		if ( false === $decoded_key || '' === $decoded_key ) {
			return array();
		}

		$date        = gmdate( 'D, d M Y H:i:s', time() ) . ' GMT';
		$content_hash = base64_encode( hash( 'sha256', (string) $body, true ) );
		$host        = (string) parse_url( $endpoint, PHP_URL_HOST );
		$string_to_sign = "POST\n" . $path_and_query . "\n" . $date . ';' . $host . ';' . $content_hash;
		$signature      = base64_encode( hash_hmac( 'sha256', $string_to_sign, $decoded_key, true ) );

		return array(
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
			'x-ms-date'     => $date,
			'x-ms-content-sha256' => $content_hash,
			'Authorization' => 'HMAC-SHA256 SignedHeaders=x-ms-date;host;x-ms-content-sha256&Signature=' . $signature,
			'Host'          => $host,
		);
	}

	/**
	 * Normalizes recipient input to ACS structures.
	 *
	 * @param mixed $value Recipient list.
	 * @return array<int, array<string, string>>
	 */
	private function recipients( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\s*,\s*/', trim( $value ) );
		}

		if ( ! is_array( $value ) ) {
			$value = array();
		}

		$recipients = array();

		foreach ( $value as $recipient ) {
			$address = sanitize_email( $recipient );

			if ( '' === $address ) {
				continue;
			}

			$recipients[] = array(
				'address' => $address,
			);
		}

		return $recipients;
	}

	/**
	 * Reads a provider retry delay from response headers.
	 *
	 * @param mixed $headers Response headers.
	 * @return int
	 */
	private function retry_after_seconds( $headers ) {
		$normalized = $this->normalize_headers( $headers );

		if ( isset( $normalized['retry-after'] ) && is_numeric( $normalized['retry-after'] ) ) {
			return max( 0, absint( $normalized['retry-after'] ) );
		}

		if ( isset( $normalized['x-ms-retry-after-ms'] ) && is_numeric( $normalized['x-ms-retry-after-ms'] ) ) {
			return max( 0, (int) ceil( absint( $normalized['x-ms-retry-after-ms'] ) / 1000 ) );
		}

		return 0;
	}

	/**
	 * Detects HTML content from message content or headers.
	 *
	 * @param string $message Mail body.
	 * @param mixed  $headers Mail headers.
	 * @return bool
	 */
	private function is_html_message( $message, $headers ) {
		if ( ! is_array( $headers ) ) {
			$headers = '' !== (string) $headers ? preg_split( '/\r\n|\r|\n/', (string) $headers ) : array();
		}

		foreach ( $headers as $header ) {
			if ( false !== stripos( (string) $header, 'text/html' ) ) {
				return true;
			}
		}

		return $message !== strip_tags( $message );
	}

	/**
	 * Builds ACS attachment payloads for readable files.
	 *
	 * @param mixed $attachments Attachment paths.
	 * @return array<int, array<string, string>>
	 */
	private function attachments( $attachments ) {
		if ( ! is_array( $attachments ) ) {
			$attachments = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $attachments ) ) );
		}

		$payloads = array();

		foreach ( $attachments as $attachment ) {
			$path = is_string( $attachment ) ? $attachment : '';

			if ( '' === $path || ! file_exists( $path ) || ! is_readable( $path ) ) {
				continue;
			}

			$content = file_get_contents( $path );

			if ( false === $content ) {
				continue;
			}

			$payloads[] = array(
				'name'          => basename( $path ),
				'contentType'   => 'application/octet-stream',
				'contentInBase64' => base64_encode( $content ),
			);
		}

		return $payloads;
	}

	/**
	 * Extracts the operation identifier from ACS headers.
	 *
	 * @param mixed $headers Response headers.
	 * @return string
	 */
	private function operation_id_from_headers( $headers ) {
		$normalized = $this->normalize_headers( $headers );

		if ( empty( $normalized['operation-location'] ) ) {
			return '';
		}

		$path = (string) parse_url( $normalized['operation-location'], PHP_URL_PATH );
		$path = trim( $path, '/' );

		if ( '' === $path ) {
			return '';
		}

		$segments = explode( '/', $path );

		return (string) end( $segments );
	}

	/**
	 * Normalizes header keys to lowercase.
	 *
	 * @param mixed $headers Response headers.
	 * @return array<string, string>
	 */
	private function normalize_headers( $headers ) {
		if ( ! is_array( $headers ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $headers as $key => $value ) {
			$normalized[ strtolower( (string) $key ) ] = is_array( $value ) ? implode( ',', $value ) : (string) $value;
		}

		return $normalized;
	}
}
