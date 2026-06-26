<?php
require_once __DIR__ . '/bootstrap.php';

wmqt_test( 'test harness records and runs assertions', function () {
	wmqt_assert_same( 'ok', 'ok', 'same assertion' );
	wmqt_assert_true( true, 'truth assertion' );
} );

wmqt_test( 'plugin header requires php compatible with production syntax', function () {
	$plugin_file = file_get_contents( __DIR__ . '/../monte-mail-queue-throttle.php' );

	preg_match( '/Requires PHP:\s*([0-9.]+)/', $plugin_file, $matches );

	wmqt_assert_true( isset( $matches[1] ), 'requires php header' );
	wmqt_assert_true( version_compare( $matches[1], '7.1', '>=' ), 'requires php 7.1 or newer' );
} );
