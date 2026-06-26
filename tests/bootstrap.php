<?php
$wmqt_tests                = array();
$wmqt_test_options         = array();
$wmqt_remote_posts         = array();
$wmqt_next_remote_response = null;

function wmqt_test( $name, callable $callback ) {
	global $wmqt_tests;
	$wmqt_tests[] = array( $name, $callback );
}

function wmqt_assert_same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) {
		throw new Exception( ( '' !== $message ? $message . ': ' : '' ) . 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

function wmqt_assert_true( $actual, $message = '' ) {
	if ( true !== (bool) $actual ) {
		throw new Exception( '' !== $message ? $message : 'expected true' );
	}
}

function wmqt_reset_test_state() {
	global $wmqt_test_options, $wmqt_remote_posts, $wmqt_next_remote_response;
	$wmqt_test_options         = array();
	$wmqt_remote_posts         = array();
	$wmqt_next_remote_response = null;
}

function get_option( $name, $default = false ) {
	global $wmqt_test_options;
	return array_key_exists( $name, $wmqt_test_options ) ? $wmqt_test_options[ $name ] : $default;
}

function update_option( $name, $value ) {
	global $wmqt_test_options;
	$changed                    = ! array_key_exists( $name, $wmqt_test_options ) || $wmqt_test_options[ $name ] !== $value;
	$wmqt_test_options[ $name ] = $value;
	return $changed;
}

function sanitize_key( $key ) {
	$key = strtolower( (string) $key );
	return preg_replace( '/[^a-z0-9_\-]/', '', $key );
}

function sanitize_title( $title ) {
	$title = strtolower( trim( (string) $title ) );
	$title = preg_replace( '/[^a-z0-9_\-]+/', '-', $title );
	return trim( $title, '-' );
}

function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', (string) $value ) );
}

function sanitize_email( $email ) {
	return trim( (string) $email );
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_remote_post( $url, $args = array() ) {
	global $wmqt_remote_posts, $wmqt_next_remote_response;

	$wmqt_remote_posts[] = array( $url, $args );

	if ( null !== $wmqt_next_remote_response ) {
		return $wmqt_next_remote_response;
	}

	return array(
		'response' => array( 'code' => 202 ),
		'headers'  => array( 'operation-location' => 'https://example/status/op-1' ),
		'body'     => '',
	);
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}

function wp_remote_retrieve_headers( $response ) {
	return isset( $response['headers'] ) && is_array( $response['headers'] ) ? $response['headers'] : array();
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? (string) $response['body'] : '';
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

class WP_Error {
	private $message;

	public function __construct( $code = '', $message = '' ) {
		unset( $code );
		$this->message = $message;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

if ( ! defined( 'WMQT_OPTION_NAME' ) ) {
	define( 'WMQT_OPTION_NAME', 'wmqt_settings' );
}
