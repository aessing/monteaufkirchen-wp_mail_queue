<?php
foreach ( glob( __DIR__ . '/*Test.php' ) as $test_file ) {
	require_once $test_file;
}

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
