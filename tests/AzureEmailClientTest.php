<?php
require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../includes/class-monte-mail-queue-settings.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-delivery-result.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-azure-email-client.php';

wmqt_test( 'azure client parses connection string', function () {
	$settings = new Monte_Mail_Queue_Settings();
	$client   = new Monte_Mail_Queue_Azure_Email_Client( $settings );

	$parsed = $client->parse_connection_string( 'endpoint=https://example.communication.azure.com/;accesskey=abc123' );

	wmqt_assert_same( 'https://example.communication.azure.com', $parsed['endpoint'], 'endpoint' );
	wmqt_assert_same( 'abc123', $parsed['accesskey'], 'access key' );
} );

wmqt_test( 'azure client maps mail payload and returns accepted operation id', function () {
	global $wmqt_remote_posts, $wmqt_next_remote_response;

	wmqt_reset_test_state();
	$wmqt_next_remote_response = array(
		'response' => array( 'code' => 202 ),
		'headers'  => array( 'operation-location' => 'https://example/status/operation-123' ),
		'body'     => '',
	);

	$settings = new Monte_Mail_Queue_Settings();
	$settings->update(
		array(
			'azure_connection_string' => 'endpoint=https://example.communication.azure.com/;accesskey=' . base64_encode( 'test-key' ),
			'azure_sender_username'   => 'DoNotReply',
			'azure_default_domain'    => 'mailing.example.com',
			'azure_reply_to'          => 'reply@example.com',
		)
	);

	$result = ( new Monte_Mail_Queue_Azure_Email_Client( $settings ) )->send(
		array(
			'to'          => array( 'user@example.com' ),
			'subject'     => 'Test Email',
			'message'     => '<p>Hello world via email.</p>',
			'headers'     => array( 'Content-Type: text/html; charset=UTF-8' ),
			'attachments' => array(),
		)
	);

	wmqt_assert_same( true, $result->accepted(), 'accepted result' );
	wmqt_assert_same( 'operation-123', $result->provider_message_id(), 'operation id' );
	wmqt_assert_same( 'https://example.communication.azure.com/emails:send?api-version=2023-03-31', $wmqt_remote_posts[0][0], 'request url' );

	$body = json_decode( $wmqt_remote_posts[0][1]['body'], true );

	wmqt_assert_same( 'DoNotReply@mailing.example.com', $body['senderAddress'], 'sender' );
	wmqt_assert_same( 'user@example.com', $body['recipients']['to'][0]['address'], 'recipient' );
	wmqt_assert_same( '<p>Hello world via email.</p>', $body['content']['html'], 'html body' );
	wmqt_assert_same( 'reply@example.com', $body['replyTo'][0]['address'], 'reply to' );
} );

wmqt_test( 'azure client maps retry headers', function () {
	global $wmqt_next_remote_response;

	wmqt_reset_test_state();
	$wmqt_next_remote_response = array(
		'response' => array( 'code' => 429 ),
		'headers'  => array( 'Retry-After' => '120' ),
		'body'     => 'too many requests',
	);

	$settings = new Monte_Mail_Queue_Settings();
	$settings->update(
		array(
			'azure_connection_string' => 'endpoint=https://example.communication.azure.com/;accesskey=' . base64_encode( 'test-key' ),
			'azure_sender_username'   => 'DoNotReply',
			'azure_default_domain'    => 'mailing.example.com',
		)
	);

	$result = ( new Monte_Mail_Queue_Azure_Email_Client( $settings ) )->send(
		array(
			'to'      => 'user@example.com',
			'subject' => 'Subject',
			'message' => 'Body',
		)
	);

	wmqt_assert_same( false, $result->accepted(), 'not accepted' );
	wmqt_assert_same( true, $result->retryable(), 'retryable' );
	wmqt_assert_same( 120, $result->retry_after_seconds(), 'retry delay' );
} );

wmqt_test( 'azure client retries invalid connection strings', function () {
	wmqt_reset_test_state();

	$settings = new Monte_Mail_Queue_Settings();
	$result   = ( new Monte_Mail_Queue_Azure_Email_Client( $settings ) )->send(
		array(
			'to'      => 'user@example.com',
			'subject' => 'Subject',
			'message' => 'Body',
		)
	);

	wmqt_assert_same( true, $result->retryable(), 'retryable' );
	wmqt_assert_same( 'Azure Communication Services connection string is invalid.', $result->error(), 'error message' );
} );
