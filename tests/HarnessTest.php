<?php
require_once __DIR__ . '/bootstrap.php';

wmqt_test( 'test harness records and runs assertions', function () {
	wmqt_assert_same( 'ok', 'ok', 'same assertion' );
	wmqt_assert_true( true, 'truth assertion' );
} );
