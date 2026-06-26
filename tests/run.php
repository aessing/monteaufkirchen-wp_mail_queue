<?php
require_once __DIR__ . '/HarnessTest.php';
require_once __DIR__ . '/SettingsTest.php';
require_once __DIR__ . '/ThrottleWindowTest.php';
require_once __DIR__ . '/WorkerCadenceTest.php';
require_once __DIR__ . '/WorkerTest.php';
require_once __DIR__ . '/AzureEmailClientTest.php';

global $wmqt_tests;
$failures = 0;

foreach ( $wmqt_tests as $test ) {
	try {
		call_user_func( $test[1] );
		echo "PASS {$test[0]}\n";
	} catch ( Throwable $throwable ) {
		$failures++;
		echo "FAIL {$test[0]}: {$throwable->getMessage()}\n";
	}
}

if ( 0 < $failures ) {
	exit( 1 );
}
