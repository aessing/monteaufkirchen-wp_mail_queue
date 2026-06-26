<?php
$wmqt_tests        = array();
$wmqt_test_options = array();

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
	global $wmqt_test_options;
	$wmqt_test_options = array();
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

if ( ! defined( 'WMQT_OPTION_NAME' ) ) {
	define( 'WMQT_OPTION_NAME', 'wmqt_settings' );
}
