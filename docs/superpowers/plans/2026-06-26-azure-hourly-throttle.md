# Azure Email And Hourly Throttle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add configurable minute, hour, and worker-cadence throttling plus optional Azure Communication Services Email REST delivery.

**Architecture:** Keep queue interception unchanged. Add a persistent send-window table for rolling throttle checks, add a focused ACS REST client, and let the worker choose between `wp_mail()` and Azure delivery. Keep Azure disabled by default so existing installs continue using their current WordPress mail transport.

**Tech Stack:** WordPress plugin PHP, `$wpdb`, WP-Cron, WordPress HTTP API, PHP built-ins, no Composer dependencies, PHP 7.0 compatible code.

## Global Constraints

- Version target is `0.5.0`.
- Azure sender configuration is manual for v1.
- Do not call Azure Resource Manager to discover domains.
- Azure delivery is disabled by default.
- Existing `wp_mail()` replay must keep working with Azure disabled.
- `rate_per_minute` default is `25`, minimum `1`.
- `rate_per_hour` default is `1500`, minimum `1`.
- `worker_interval_minutes` default is `2`, minimum `1`, maximum `60`.
- Target hosting can set `worker_interval_minutes` to `1` because `wp-cron.php` is called every minute.
- The rolling minute and hour throttle is the hard delivery limit.
- The worker does not sleep inside WP-Cron.
- ACS client sends with `POST {endpoint}/emails:send?api-version=2023-03-31`.
- ACS uses the connection string as the primary admin input.
- ACS request signing uses endpoint and access key parsed from the connection string.
- Use WordPress HTTP APIs, not direct cURL.
- No Composer package requirement.
- New code must avoid PHP features newer than PHP 7.0.

---

## File Structure

- `tests/bootstrap.php`: Minimal WordPress function stubs and test globals.
- `tests/run.php`: Small PHP test runner for unit-style tests without WordPress.
- `tests/HarnessTest.php`: Sanity test proving the lightweight test harness runs.
- `tests/SettingsTest.php`: Tests for new settings defaults and sanitization.
- `tests/ThrottleWindowTest.php`: Tests for rolling minute and hour decisions.
- `tests/AzureEmailClientTest.php`: Tests for ACS connection parsing, payload mapping, and retry result mapping.
- `tests/WorkerTest.php`: Tests for worker transport selection and throttle behavior with fake dependencies.
- `monte-mail-queue-throttle.php`: Version bump and new class includes.
- `includes/class-monte-mail-queue-settings.php`: New settings defaults and sanitization.
- `includes/class-monte-mail-queue-installer.php`: Send-window schema and configurable cron cadence.
- `includes/class-monte-mail-queue-repository.php`: Send-window persistence and usage queries.
- `includes/class-monte-mail-queue-throttle-window.php`: Pure throttle decision service backed by repository usage.
- `includes/class-monte-mail-queue-delivery-result.php`: Value object used by transports and worker.
- `includes/class-monte-mail-queue-azure-email-client.php`: ACS REST delivery client.
- `includes/class-monte-mail-queue-worker.php`: Transport selection, throttle checks, Azure retry feedback.
- `includes/class-monte-mail-queue-admin.php`: Settings fields, test-mail form, and new dashboard data.
- `includes/class-monte-mail-queue-plugin.php`: Dependency wiring.
- `assets/admin.css`: Styling for Azure settings and test-mail panel.
- `README.md`: Updated behavior and configuration docs.
- `monte-mail-queue-throttle.zip`: Rebuilt upload package.

---

### Task 1: Add Lightweight PHP Test Harness

**Files:**
- Create: `tests/bootstrap.php`
- Create: `tests/run.php`
- Create: `tests/HarnessTest.php`

**Interfaces:**
- Produces: `wmqt_test(string $name, callable $callback)`
- Produces: `wmqt_assert_same($expected, $actual, string $message = '')`
- Produces: `wmqt_assert_true($actual, string $message = '')`
- Produces: `wmqt_reset_test_state()`
- Produces: global `$wmqt_test_options`

- [ ] **Step 1: Create the test runner and stubs**

Create `tests/bootstrap.php` with these stubs and assertions:

```php
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
```

Create `tests/HarnessTest.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';

wmqt_test( 'test harness records and runs assertions', function () {
	wmqt_assert_same( 'ok', 'ok', 'same assertion' );
	wmqt_assert_true( true, 'truth assertion' );
} );
```

Create `tests/run.php`:

```php
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
```

- [ ] **Step 2: Run tests to verify the harness passes**

Run:

```bash
php tests/run.php
```

Expected: `PASS test harness records and runs assertions`.

- [ ] **Step 3: Commit the passing harness**

Run:

```bash
git add tests/bootstrap.php tests/run.php tests/HarnessTest.php
git commit --no-gpg-sign -m "test: add php test harness"
```

---

### Task 2: Add Settings And Configurable Worker Cadence

**Files:**
- Modify: `includes/class-monte-mail-queue-settings.php`
- Modify: `includes/class-monte-mail-queue-installer.php`
- Modify: `includes/class-monte-mail-queue-admin.php`
- Modify: `includes/class-monte-mail-queue-plugin.php`
- Modify: `tests/SettingsTest.php`

**Interfaces:**
- Produces setting key: `rate_per_hour`
- Produces setting key: `worker_interval_minutes`
- Produces setting key: `azure_email_enabled`
- Produces setting key: `azure_connection_string`
- Produces setting key: `azure_sender_domains`
- Produces setting key: `azure_sender_username`
- Produces setting key: `azure_default_domain`
- Produces setting key: `azure_reply_to`
- Produces: `Monte_Mail_Queue_Installer::reschedule_event()`

- [ ] **Step 1: Write failing settings tests**

Create `tests/SettingsTest.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-settings.php';

wmqt_test( 'settings expose new throttle and azure defaults', function () {
	wmqt_reset_test_state();

	$settings = new Monte_Mail_Queue_Settings();
	$all      = $settings->get_all();

	wmqt_assert_same( 25, $all['rate_per_minute'], 'minute default' );
	wmqt_assert_same( 1500, $all['rate_per_hour'], 'hour default' );
	wmqt_assert_same( 2, $all['worker_interval_minutes'], 'worker interval default' );
	wmqt_assert_same( 0, $all['azure_email_enabled'], 'azure disabled default' );
	wmqt_assert_same( '', $all['azure_connection_string'], 'connection string default' );
	wmqt_assert_same( '', $all['azure_sender_domains'], 'sender domains default' );
	wmqt_assert_same( 'DoNotReply', $all['azure_sender_username'], 'sender username default' );
} );

wmqt_test( 'settings sanitize worker cadence and azure fields', function () {
	wmqt_reset_test_state();

	$settings = new Monte_Mail_Queue_Settings();
	$settings->update(
		array(
			'rate_per_hour'           => '0',
			'worker_interval_minutes' => '90',
			'azure_email_enabled'     => '1',
			'azure_connection_string' => " endpoint=https://example.communication.azure.com/;accesskey=secret \n",
			'azure_sender_domains'    => "mailing.example.com\n MAILING.example.com, bad space.test ",
			'azure_sender_username'   => ' Do Not Reply ',
			'azure_default_domain'    => ' MAILING.example.com ',
			'azure_reply_to'          => ' Reply@Example.com ',
		)
	);

	$all = $settings->get_all();

	wmqt_assert_same( 1, $all['rate_per_hour'], 'hour minimum' );
	wmqt_assert_same( 60, $all['worker_interval_minutes'], 'worker interval maximum' );
	wmqt_assert_same( 1, $all['azure_email_enabled'], 'azure checkbox' );
	wmqt_assert_same( 'endpoint=https://example.communication.azure.com/;accesskey=secret', $all['azure_connection_string'], 'connection string trim' );
	wmqt_assert_same( 'mailing.example.com,badspace.test', $all['azure_sender_domains'], 'domain cleanup' );
	wmqt_assert_same( 'DoNotReply', $all['azure_sender_username'], 'username cleanup' );
	wmqt_assert_same( 'mailing.example.com', $all['azure_default_domain'], 'default domain cleanup' );
	wmqt_assert_same( 'Reply@Example.com', $all['azure_reply_to'], 'reply-to cleanup' );
} );
```

- [ ] **Step 2: Run settings tests to verify RED**

Run:

```bash
php tests/run.php
```

Expected: FAIL because settings do not yet expose `rate_per_hour`, `worker_interval_minutes`, or Azure settings.

- [ ] **Step 3: Implement settings sanitization**

Modify `Monte_Mail_Queue_Settings::$defaults` to include:

```php
'rate_per_hour'           => 1500,
'worker_interval_minutes' => 2,
'azure_email_enabled'     => 0,
'azure_connection_string' => '',
'azure_sender_domains'    => '',
'azure_sender_username'   => 'DoNotReply',
'azure_default_domain'    => '',
'azure_reply_to'          => '',
```

Modify `sanitize()` to return these keys:

```php
'rate_per_hour'           => max( 1, absint( $settings['rate_per_hour'] ?? $this->defaults['rate_per_hour'] ) ),
'worker_interval_minutes' => min( 60, max( 1, absint( $settings['worker_interval_minutes'] ?? $this->defaults['worker_interval_minutes'] ) ) ),
'azure_email_enabled'     => empty( $settings['azure_email_enabled'] ) ? 0 : 1,
'azure_connection_string' => $this->sanitize_connection_string( $settings['azure_connection_string'] ?? '' ),
'azure_sender_domains'    => $this->sanitize_sender_domains( $settings['azure_sender_domains'] ?? '' ),
'azure_sender_username'   => $this->sanitize_sender_username( $settings['azure_sender_username'] ?? $this->defaults['azure_sender_username'] ),
'azure_default_domain'    => $this->sanitize_domain( $settings['azure_default_domain'] ?? '' ),
'azure_reply_to'          => sanitize_email( $settings['azure_reply_to'] ?? '' ),
```

Add private helpers:

```php
private function sanitize_connection_string( $value ) {
	return trim( preg_replace( '/[\r\n\t]+/', '', (string) $value ) );
}

private function sanitize_sender_domains( $value ) {
	if ( is_array( $value ) ) {
		$value = implode( ',', $value );
	}

	$domains = preg_split( '/[\r\n,]+/', (string) $value );
	$domains = array_filter( array_map( array( $this, 'sanitize_domain' ), $domains ) );

	return implode( ',', array_unique( $domains ) );
}

private function sanitize_sender_username( $value ) {
	$value = preg_replace( '/[^A-Za-z0-9._%+\-]/', '', (string) $value );
	return '' !== $value ? $value : $this->defaults['azure_sender_username'];
}

private function sanitize_domain( $value ) {
	$value = strtolower( trim( (string) $value ) );
	$value = preg_replace( '/[^a-z0-9.\-]/', '', $value );
	return trim( $value, '.-' );
}
```

- [ ] **Step 4: Run settings tests to verify GREEN**

Run:

```bash
php tests/run.php
```

Expected: PASS for both settings tests.

- [ ] **Step 5: Add installer cadence methods**

Modify `Monte_Mail_Queue_Installer::add_cron_schedule()` so the schedule interval is:

```php
$minutes = max( 1, min( 60, absint( $this->settings->get( 'worker_interval_minutes', 2 ) ) ) );
$seconds = $minutes * MINUTE_IN_SECONDS;
```

Use `$seconds` as the schedule interval and display `Every %d minute(s)`.

Add public method:

```php
public function reschedule_event() {
	wp_clear_scheduled_hook( WMQT_CRON_HOOK );
	$this->schedule_event();
}
```

Modify `schedule_event()` so first run uses the configured interval:

```php
$minutes = max( 1, min( 60, absint( $this->settings->get( 'worker_interval_minutes', 2 ) ) ) );
wp_schedule_event( time() + ( $minutes * MINUTE_IN_SECONDS ), WMQT_CRON_SCHEDULE, WMQT_CRON_HOOK );
```

- [ ] **Step 6: Wire admin save to reschedule on interval change**

Modify `Monte_Mail_Queue_Admin` constructor to accept `Monte_Mail_Queue_Installer $installer`, store it, and update `Monte_Mail_Queue_Plugin::admin()` to pass `$this->installer`.

In `save_settings()`, capture old settings before update:

```php
$previous = $this->settings->get_all();
```

After update, compare:

```php
$current = $this->settings->get_all();
if ( (int) $previous['worker_interval_minutes'] !== (int) $current['worker_interval_minutes'] ) {
	$this->installer->reschedule_event();
}
```

Add posted fields for `rate_per_hour`, `worker_interval_minutes`, and the Azure settings to the update array.

- [ ] **Step 7: Render new settings fields**

In `render_settings()`, add number fields:

```php
$this->render_number_field( 'rate_per_hour', __( 'Mails per hour', 'monte-mail-queue-throttle' ), $settings['rate_per_hour'] ?? 1500 );
$this->render_number_field( 'worker_interval_minutes', __( 'Worker interval minutes', 'monte-mail-queue-throttle' ), $settings['worker_interval_minutes'] ?? 2, __( 'Set to 1 when wp-cron.php is called every minute.', 'monte-mail-queue-throttle' ) );
```

Add checkbox, textarea, and email helper render methods if missing:

```php
private function render_checkbox_field( $name, $label, $value, $description = '' ) {
	$description_html = '' !== $description ? '<p class="description">' . esc_html( $description ) . '</p>' : '';

	printf(
		'<tr><th scope="row">%2$s</th><td><label><input name="%1$s" id="%1$s" type="checkbox" value="1" %3$s> %4$s</label>%5$s</td></tr>',
		esc_attr( $name ),
		esc_html( $label ),
		checked( 1, (int) $value, false ),
		esc_html__( 'Enabled', 'monte-mail-queue-throttle' ),
		$description_html
	);
}

private function render_textarea_field( $name, $label, $value, $description = '' ) {
	$description_html = '' !== $description ? '<p class="description">' . esc_html( $description ) . '</p>' : '';

	printf(
		'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><textarea name="%1$s" id="%1$s" rows="4" class="large-text code">%3$s</textarea>%4$s</td></tr>',
		esc_attr( $name ),
		esc_html( $label ),
		esc_textarea( (string) $value ),
		$description_html
	);
}

private function render_email_field( $name, $label, $value, $description = '' ) {
	$description_html = '' !== $description ? '<p class="description">' . esc_html( $description ) . '</p>' : '';

	printf(
		'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input name="%1$s" id="%1$s" type="email" value="%3$s" class="regular-text">%4$s</td></tr>',
		esc_attr( $name ),
		esc_html( $label ),
		esc_attr( (string) $value ),
		$description_html
	);
}
```

Use them for Azure settings.

- [ ] **Step 8: Run syntax and tests**

Run:

```bash
php tests/run.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: all tests pass and all PHP files report no syntax errors.

- [ ] **Step 9: Commit**

Run:

```bash
git add includes/class-monte-mail-queue-settings.php includes/class-monte-mail-queue-installer.php includes/class-monte-mail-queue-admin.php includes/class-monte-mail-queue-plugin.php tests/SettingsTest.php
git commit --no-gpg-sign -m "feat: configure hourly throttle and worker cadence"
```

---

### Task 3: Add Send-Window Throttle Service

**Files:**
- Create: `includes/class-monte-mail-queue-throttle-window.php`
- Create: `tests/ThrottleWindowTest.php`
- Modify: `tests/run.php`
- Modify: `includes/class-monte-mail-queue-installer.php`
- Modify: `includes/class-monte-mail-queue-repository.php`
- Modify: `monte-mail-queue-throttle.php`

**Interfaces:**
- Produces: `Monte_Mail_Queue_Throttle_Window::status(string $transport): array`
- Produces: `Monte_Mail_Queue_Throttle_Window::record_accepted(int $queue_id, string $transport, string $provider_message_id = '')`
- Produces: `Monte_Mail_Queue_Throttle_Window::prune()`
- Produces repository method: `send_window_usage(string $transport): array`
- Produces repository method: `record_send_window(int $queue_id, string $transport, string $provider_message_id = ''): bool`
- Produces repository method: `purge_old_send_windows(int $hours = 48): int`

- [ ] **Step 1: Write failing throttle tests**

Create `tests/ThrottleWindowTest.php` with fake repository usage:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-settings.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-repository.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-throttle-window.php';

class Wmqt_Fake_Window_Repository extends Monte_Mail_Queue_Repository {
	public $usage = array( 'minute' => 0, 'hour' => 0 );
	public $recorded = array();
	public $purged_hours = 0;

	public function send_window_usage( string $transport ): array {
		return $this->usage;
	}

	public function record_send_window( int $queue_id, string $transport, string $provider_message_id = '' ): bool {
		$this->recorded[] = array( $queue_id, $transport, $provider_message_id );
		return true;
	}

	public function purge_old_send_windows( int $hours = 48 ): int {
		$this->purged_hours = $hours;
		return 0;
	}
}

wmqt_test( 'throttle allows send below minute and hour limits', function () {
	wmqt_reset_test_state();
	$settings = new Monte_Mail_Queue_Settings();
	$settings->update( array( 'rate_per_minute' => 25, 'rate_per_hour' => 1500 ) );
	$repo = new Wmqt_Fake_Window_Repository( $settings );
	$repo->usage = array( 'minute' => 24, 'hour' => 1499 );

	$status = ( new Monte_Mail_Queue_Throttle_Window( $settings, $repo ) )->status( 'wp_mail' );

	wmqt_assert_same( true, $status['allowed'], 'allowed' );
	wmqt_assert_same( '', $status['reason'], 'reason' );
	wmqt_assert_same( 24, $status['minute_used'], 'minute used' );
	wmqt_assert_same( 1499, $status['hour_used'], 'hour used' );
} );

wmqt_test( 'throttle blocks when minute limit is reached', function () {
	wmqt_reset_test_state();
	$settings = new Monte_Mail_Queue_Settings();
	$settings->update( array( 'rate_per_minute' => 25, 'rate_per_hour' => 1500 ) );
	$repo = new Wmqt_Fake_Window_Repository( $settings );
	$repo->usage = array( 'minute' => 25, 'hour' => 100 );

	$status = ( new Monte_Mail_Queue_Throttle_Window( $settings, $repo ) )->status( 'wp_mail' );

	wmqt_assert_same( false, $status['allowed'], 'blocked' );
	wmqt_assert_same( 'minute', $status['reason'], 'reason' );
} );

wmqt_test( 'throttle blocks when hour limit is reached', function () {
	wmqt_reset_test_state();
	$settings = new Monte_Mail_Queue_Settings();
	$settings->update( array( 'rate_per_minute' => 25, 'rate_per_hour' => 100 ) );
	$repo = new Wmqt_Fake_Window_Repository( $settings );
	$repo->usage = array( 'minute' => 1, 'hour' => 100 );

	$status = ( new Monte_Mail_Queue_Throttle_Window( $settings, $repo ) )->status( 'azure_communication_email' );

	wmqt_assert_same( false, $status['allowed'], 'blocked' );
	wmqt_assert_same( 'hour', $status['reason'], 'reason' );
} );

wmqt_test( 'throttle records accepted sends and prunes forty eight hours', function () {
	wmqt_reset_test_state();
	$settings = new Monte_Mail_Queue_Settings();
	$repo     = new Wmqt_Fake_Window_Repository( $settings );
	$window   = new Monte_Mail_Queue_Throttle_Window( $settings, $repo );

	$window->record_accepted( 123, 'azure_communication_email', 'op-1' );
	$window->prune();

	wmqt_assert_same( array( array( 123, 'azure_communication_email', 'op-1' ) ), $repo->recorded, 'recorded send' );
	wmqt_assert_same( 48, $repo->purged_hours, 'prune window' );
} );
```

Modify `tests/run.php` to require `ThrottleWindowTest.php`.

- [ ] **Step 2: Run tests to verify RED**

Run:

```bash
php tests/run.php
```

Expected: FAIL because `class-monte-mail-queue-throttle-window.php` does not exist.

- [ ] **Step 3: Implement throttle service**

Create `includes/class-monte-mail-queue-throttle-window.php` with class:

```php
class Monte_Mail_Queue_Throttle_Window {
	private $settings;
	private $repository;

	public function __construct( Monte_Mail_Queue_Settings $settings, Monte_Mail_Queue_Repository $repository ) {
		$this->settings   = $settings;
		$this->repository = $repository;
	}

	public function status( $transport ) {
		$transport    = sanitize_key( $transport );
		$usage        = $this->repository->send_window_usage( $transport );
		$minute_limit = max( 1, absint( $this->settings->get( 'rate_per_minute', 25 ) ) );
		$hour_limit   = max( 1, absint( $this->settings->get( 'rate_per_hour', 1500 ) ) );
		$minute_used  = (int) ( $usage['minute'] ?? 0 );
		$hour_used    = (int) ( $usage['hour'] ?? 0 );

		if ( $minute_used >= $minute_limit ) {
			return $this->decision( false, 'minute', $minute_used, $hour_used, $minute_limit, $hour_limit );
		}

		if ( $hour_used >= $hour_limit ) {
			return $this->decision( false, 'hour', $minute_used, $hour_used, $minute_limit, $hour_limit );
		}

		return $this->decision( true, '', $minute_used, $hour_used, $minute_limit, $hour_limit );
	}

	public function record_accepted( $queue_id, $transport, $provider_message_id = '' ) {
		$this->repository->record_send_window( absint( $queue_id ), sanitize_key( $transport ), (string) $provider_message_id );
	}

	public function prune() {
		$this->repository->purge_old_send_windows( 48 );
	}

	private function decision( $allowed, $reason, $minute_used, $hour_used, $minute_limit, $hour_limit ) {
		return array(
			'allowed'      => (bool) $allowed,
			'reason'       => (string) $reason,
			'minute_used'  => (int) $minute_used,
			'hour_used'    => (int) $hour_used,
			'minute_limit' => (int) $minute_limit,
			'hour_limit'   => (int) $hour_limit,
		);
	}
}
```

- [ ] **Step 4: Add table schema and repository methods**

Modify installer `create_tables()` to create `{prefix}wmqt_send_windows`:

```sql
CREATE TABLE {$send_windows_table} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	queue_id bigint(20) unsigned NOT NULL DEFAULT 0,
	transport varchar(50) NOT NULL DEFAULT '',
	accepted_at datetime NOT NULL,
	provider_message_id varchar(255) NOT NULL DEFAULT '',
	PRIMARY KEY  (id),
	KEY accepted_at (accepted_at),
	KEY transport_accepted_at (transport, accepted_at),
	KEY queue_id (queue_id)
) {$charset_collate};
```

Add repository methods:

```php
public function send_window_usage( string $transport ): array
public function record_send_window( int $queue_id, string $transport, string $provider_message_id = '' ): bool
public function purge_old_send_windows( int $hours = 48 ): int
private function send_windows_table(): string
```

`send_window_usage()` returns:

```php
array(
	'minute' => (int) $minute_count,
	'hour'   => (int) $hour_count,
)
```

Use `current_time( 'mysql' )` with SQL `DATE_SUB(%s, INTERVAL 60 SECOND)` and `DATE_SUB(%s, INTERVAL 3600 SECOND)`.

- [ ] **Step 5: Include the new class**

Modify `monte-mail-queue-throttle.php` to require `includes/class-monte-mail-queue-throttle-window.php` after repository.

- [ ] **Step 6: Run tests and syntax**

Run:

```bash
php tests/run.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: all tests pass and all PHP files report no syntax errors.

- [ ] **Step 7: Commit**

Run:

```bash
git add monte-mail-queue-throttle.php includes/class-monte-mail-queue-installer.php includes/class-monte-mail-queue-repository.php includes/class-monte-mail-queue-throttle-window.php tests/ThrottleWindowTest.php tests/run.php
git commit --no-gpg-sign -m "feat: add rolling send throttle window"
```

---

### Task 4: Add Delivery Result And Azure Email Client

**Files:**
- Create: `includes/class-monte-mail-queue-delivery-result.php`
- Create: `includes/class-monte-mail-queue-azure-email-client.php`
- Create: `tests/AzureEmailClientTest.php`
- Modify: `tests/bootstrap.php`
- Modify: `tests/run.php`
- Modify: `monte-mail-queue-throttle.php`

**Interfaces:**
- Produces: `Monte_Mail_Queue_Delivery_Result::accepted_result(string $provider_message_id = '', int $code = 0): self`
- Produces: `Monte_Mail_Queue_Delivery_Result::retry_result(string $error, int $retry_after_seconds = 0, int $code = 0): self`
- Produces: `Monte_Mail_Queue_Delivery_Result::failed_result(string $error, int $code = 0): self`
- Produces: `Monte_Mail_Queue_Azure_Email_Client::send(array $mail, array $overrides = array()): Monte_Mail_Queue_Delivery_Result`
- Produces: `Monte_Mail_Queue_Azure_Email_Client::parse_connection_string(string $connection_string): array`

- [ ] **Step 1: Add WordPress HTTP stubs to tests**

Extend `tests/bootstrap.php`:

```php
$wmqt_remote_posts = array();
$wmqt_next_remote_response = null;

function wp_remote_post( $url, $args = array() ) {
	global $wmqt_remote_posts, $wmqt_next_remote_response;
	$wmqt_remote_posts[] = array( $url, $args );
	return null !== $wmqt_next_remote_response ? $wmqt_next_remote_response : array( 'response' => array( 'code' => 202 ), 'headers' => array( 'operation-location' => 'https://example/status/op-1' ), 'body' => '' );
}

function wp_remote_retrieve_response_code( $response ) {
	return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_headers( $response ) {
	return $response['headers'] ?? array();
}

function wp_remote_retrieve_body( $response ) {
	return (string) ( $response['body'] ?? '' );
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

class WP_Error {
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->message = $message;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}
```

- [ ] **Step 2: Write failing Azure tests**

Create `tests/AzureEmailClientTest.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
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
	$wmqt_remote_posts = array();
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

	$result = ( new Monte_Mail_Queue_Azure_Email_Client( $settings ) )->send( array( 'to' => 'user@example.com', 'subject' => 'Subject', 'message' => 'Body' ) );

	wmqt_assert_same( false, $result->accepted(), 'not accepted' );
	wmqt_assert_same( true, $result->retryable(), 'retryable' );
	wmqt_assert_same( 120, $result->retry_after_seconds(), 'retry delay' );
} );
```

Modify `tests/run.php` to require `AzureEmailClientTest.php`.

- [ ] **Step 3: Run tests to verify RED**

Run:

```bash
php tests/run.php
```

Expected: FAIL because delivery result and Azure client classes do not exist.

- [ ] **Step 4: Implement delivery result**

Create `includes/class-monte-mail-queue-delivery-result.php` with immutable-style object methods:

```php
class Monte_Mail_Queue_Delivery_Result {
	private $status;
	private $error;
	private $retry_after_seconds;
	private $provider_message_id;
	private $response_code;

	private function __construct( $status, $error, $retry_after_seconds, $provider_message_id, $response_code ) {
		$this->status              = $status;
		$this->error               = $error;
		$this->retry_after_seconds = max( 0, absint( $retry_after_seconds ) );
		$this->provider_message_id = (string) $provider_message_id;
		$this->response_code       = absint( $response_code );
	}

	public static function accepted_result( $provider_message_id = '', $code = 0 ) {
		return new self( 'accepted', '', 0, $provider_message_id, $code );
	}

	public static function retry_result( $error, $retry_after_seconds = 0, $code = 0 ) {
		return new self( 'retry', (string) $error, $retry_after_seconds, '', $code );
	}

	public static function failed_result( $error, $code = 0 ) {
		return new self( 'failed', (string) $error, 0, '', $code );
	}

	public function accepted() { return 'accepted' === $this->status; }
	public function retryable() { return 'retry' === $this->status; }
	public function failed() { return 'failed' === $this->status; }
	public function error() { return $this->error; }
	public function retry_after_seconds() { return $this->retry_after_seconds; }
	public function provider_message_id() { return $this->provider_message_id; }
	public function response_code() { return $this->response_code; }
}
```

- [ ] **Step 5: Implement Azure client**

Create `includes/class-monte-mail-queue-azure-email-client.php` with:

```php
class Monte_Mail_Queue_Azure_Email_Client {
	private $settings;

	public function __construct( Monte_Mail_Queue_Settings $settings )
	public function send( array $mail, array $overrides = array() )
	public function parse_connection_string( $connection_string )
	private function payload( array $mail, array $overrides, $sender_address )
	private function auth_headers( $endpoint, $access_key, $path_and_query, $body )
	private function recipients( $value )
	private function retry_after_seconds( $headers )
}
```

Payload rules:

- Treat array or comma-separated `to` as recipients.
- Use configured sender username and default domain unless overrides provide `sender_username` and `sender_domain`.
- Detect HTML when a header contains `text/html` or the message contains HTML tags.
- Attachments are added only when file paths exist and can be read.
- Attachment content is base64 encoded.

Result rules:

- HTTP `202` returns accepted with operation ID parsed from `operation-location` last path segment.
- HTTP `429`, `500`, `502`, `503`, `504`, and `WP_Error` return retry.
- Other HTTP status codes return failed.
- Missing or invalid connection string returns retry with message `Azure Communication Services connection string is invalid.`

- [ ] **Step 6: Include classes**

Modify `monte-mail-queue-throttle.php` to require delivery result and Azure client before worker.

- [ ] **Step 7: Run tests and syntax**

Run:

```bash
php tests/run.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: all tests pass and all PHP files report no syntax errors.

- [ ] **Step 8: Commit**

Run:

```bash
git add monte-mail-queue-throttle.php includes/class-monte-mail-queue-delivery-result.php includes/class-monte-mail-queue-azure-email-client.php tests/AzureEmailClientTest.php tests/bootstrap.php tests/run.php
git commit --no-gpg-sign -m "feat: add azure email rest client"
```

---

### Task 5: Integrate Throttling And Azure Delivery Into Worker

**Files:**
- Create: `tests/WorkerTest.php`
- Modify: `tests/run.php`
- Modify: `includes/class-monte-mail-queue-worker.php`
- Modify: `includes/class-monte-mail-queue-plugin.php`
- Modify: `includes/class-monte-mail-queue-repository.php`

**Interfaces:**
- Consumes: `Monte_Mail_Queue_Throttle_Window::status()`
- Consumes: `Monte_Mail_Queue_Throttle_Window::record_accepted()`
- Consumes: `Monte_Mail_Queue_Azure_Email_Client::send()`
- Produces: worker transport setting key `azure_email_enabled`
- Produces log events `throttled_minute`, `throttled_hour`, `azure_send_accepted`

- [ ] **Step 1: Write failing worker tests**

Create `tests/WorkerTest.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-settings.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-repository.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-source-detector.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-interceptor.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-delivery-result.php';
require_once __DIR__ . '/../includes/class-monte-mail-queue-worker.php';

class Wmqt_Fake_Worker_Repository extends Monte_Mail_Queue_Repository {
	public $items = array();
	public $claimed = 0;
	public $sent = array();
	public $retried = array();
	public $logs = array();

	public function recover_stale_processing_items(): void {}
	public function purge_old_logs(): int { return 0; }
	public function purge_old_queue_items(): int { return 0; }
	public function claim_batch( int $limit ): array {
		$this->claimed += $limit;
		return empty( $this->items ) ? array() : array( array_shift( $this->items ) );
	}
	public function mark_sent( int $id ): bool { $this->sent[] = $id; return true; }
	public function mark_retry( int $id, string $error, int $delay_seconds ): bool { $this->retried[] = array( $id, $error, $delay_seconds ); return true; }
	public function log( int $queue_id, string $event_type, string $message, string $source_plugin = '' ): void { $this->logs[] = array( $queue_id, $event_type, $message, $source_plugin ); }
}

class Wmqt_Fake_Throttle_Window {
	public $status;
	public $recorded = array();
	public function __construct( $status ) { $this->status = $status; }
	public function status( $transport ) { return $this->status; }
	public function record_accepted( $queue_id, $transport, $provider_message_id = '' ) { $this->recorded[] = array( $queue_id, $transport, $provider_message_id ); }
	public function prune() {}
}

class Wmqt_Fake_Azure_Client {
	public $result;
	public $sent_mail = array();
	public function __construct( $result ) { $this->result = $result; }
	public function send( array $mail, array $overrides = array() ) { $this->sent_mail[] = $mail; return $this->result; }
}

wmqt_test( 'worker stops before claiming when minute throttle is full', function () {
	wmqt_reset_test_state();
	$settings = new Monte_Mail_Queue_Settings();
	$repo = new Wmqt_Fake_Worker_Repository( $settings );
	$throttle = new Wmqt_Fake_Throttle_Window( array( 'allowed' => false, 'reason' => 'minute', 'minute_used' => 25, 'hour_used' => 100, 'minute_limit' => 25, 'hour_limit' => 1500 ) );
	$worker = new Monte_Mail_Queue_Worker( $settings, $repo, new Monte_Mail_Queue_Interceptor( $settings, $repo, new Monte_Mail_Queue_Source_Detector() ), $throttle, new Wmqt_Fake_Azure_Client( Monte_Mail_Queue_Delivery_Result::accepted_result( 'op-1', 202 ) ) );

	$worker->process_queue();

	wmqt_assert_same( 0, $repo->claimed, 'claim count' );
	wmqt_assert_same( 'throttled_minute', $repo->logs[0][1], 'log event' );
} );

wmqt_test( 'worker records azure accepted send', function () {
	wmqt_reset_test_state();
	$settings = new Monte_Mail_Queue_Settings();
	$settings->update( array( 'azure_email_enabled' => 1, 'worker_interval_minutes' => 1 ) );
	$repo = new Wmqt_Fake_Worker_Repository( $settings );
	$repo->items = array( array( 'id' => 7, 'to' => 'user@example.com', 'subject' => 'Subject', 'message' => 'Body', 'attachments' => array(), 'attempts' => 0, 'max_attempts' => 3, 'source_plugin' => '' ) );
	$throttle = new Wmqt_Fake_Throttle_Window( array( 'allowed' => true, 'reason' => '', 'minute_used' => 0, 'hour_used' => 0, 'minute_limit' => 25, 'hour_limit' => 1500 ) );
	$azure = new Wmqt_Fake_Azure_Client( Monte_Mail_Queue_Delivery_Result::accepted_result( 'op-123', 202 ) );
	$worker = new Monte_Mail_Queue_Worker( $settings, $repo, new Monte_Mail_Queue_Interceptor( $settings, $repo, new Monte_Mail_Queue_Source_Detector() ), $throttle, $azure );

	$worker->process_queue();

	wmqt_assert_same( array( 7 ), $repo->sent, 'sent item' );
	wmqt_assert_same( array( array( 7, 'azure_communication_email', 'op-123' ) ), $throttle->recorded, 'accepted window' );
	wmqt_assert_same( 'azure_send_accepted', $repo->logs[0][1], 'provider log' );
} );

wmqt_test( 'worker uses azure retry delay when provider throttles', function () {
	wmqt_reset_test_state();
	$settings = new Monte_Mail_Queue_Settings();
	$settings->update( array( 'azure_email_enabled' => 1, 'worker_interval_minutes' => 1 ) );
	$repo = new Wmqt_Fake_Worker_Repository( $settings );
	$repo->items = array( array( 'id' => 8, 'to' => 'user@example.com', 'subject' => 'Subject', 'message' => 'Body', 'attachments' => array(), 'attempts' => 0, 'max_attempts' => 3, 'source_plugin' => '' ) );
	$throttle = new Wmqt_Fake_Throttle_Window( array( 'allowed' => true, 'reason' => '', 'minute_used' => 0, 'hour_used' => 0, 'minute_limit' => 25, 'hour_limit' => 1500 ) );
	$azure = new Wmqt_Fake_Azure_Client( Monte_Mail_Queue_Delivery_Result::retry_result( 'Too many requests', 120, 429 ) );
	$worker = new Monte_Mail_Queue_Worker( $settings, $repo, new Monte_Mail_Queue_Interceptor( $settings, $repo, new Monte_Mail_Queue_Source_Detector() ), $throttle, $azure );

	$worker->process_queue();

	wmqt_assert_same( array( array( 8, 'Too many requests', 120 ) ), $repo->retried, 'retry delay' );
} );
```

Modify `tests/run.php` to require `WorkerTest.php`.

- [ ] **Step 2: Run tests to verify RED**

Run:

```bash
php tests/run.php
```

Expected: FAIL because the worker constructor does not accept throttle and Azure dependencies.

- [ ] **Step 3: Update worker constructor and transport selection**

Modify worker constructor:

```php
public function __construct(
	Monte_Mail_Queue_Settings $settings,
	Monte_Mail_Queue_Repository $repository,
	Monte_Mail_Queue_Interceptor $interceptor,
	$throttle_window = null,
	$azure_client = null
) {
	$this->settings        = $settings;
	$this->repository      = $repository;
	$this->interceptor     = $interceptor;
	$this->throttle_window = $throttle_window;
	$this->azure_client    = $azure_client;
}
```

Add private method:

```php
private function transport() {
	return 1 === (int) $this->settings->get( 'azure_email_enabled', 0 ) ? 'azure_communication_email' : 'wp_mail';
}
```

- [ ] **Step 4: Enforce throttle before claims**

At the start of each loop iteration in `process_queue()`:

```php
$transport = $this->transport();
$status    = $this->throttle_window ? $this->throttle_window->status( $transport ) : array( 'allowed' => true );

if ( empty( $status['allowed'] ) ) {
	$this->repository->log( 0, 'minute' === $status['reason'] ? 'throttled_minute' : 'throttled_hour', $this->throttle_message( $status ), '' );
	break;
}
```

Use per-run limit:

```php
$limit = max( 1, absint( $this->settings->get( 'rate_per_minute', 25 ) ) * absint( $this->settings->get( 'worker_interval_minutes', 2 ) ) );
```

- [ ] **Step 5: Split delivery by transport**

Add private `deliver_item( array $item, $transport )`:

```php
if ( 'azure_communication_email' === $transport ) {
	return $this->azure_client->send( $item );
}

$this->interceptor->enable_bypass();
try {
	$sent = wp_mail(
		$item['to'] ?? '',
		(string) ( $item['subject'] ?? '' ),
		(string) ( $item['message'] ?? '' ),
		$item['headers'] ?? '',
		$item['attachments'] ?? array()
	);
	return true === $sent ? Monte_Mail_Queue_Delivery_Result::accepted_result( '', 0 ) : Monte_Mail_Queue_Delivery_Result::failed_result( 'wp_mail returned false.' );
} finally {
	$this->interceptor->disable_bypass();
}
```

In `process_item()`, mark sent when result is accepted, record throttle window, and log Azure provider feedback:

```php
if ( $result->accepted() ) {
	$this->throttle_window->record_accepted( $id, $transport, $result->provider_message_id() );
	if ( 'azure_communication_email' === $transport && '' !== $result->provider_message_id() ) {
		$this->repository->log( $id, 'azure_send_accepted', 'Azure accepted send operation: ' . $result->provider_message_id(), $source_plugin );
	}
	if ( $this->repository->mark_sent( $id ) ) {
		$this->repository->log( $id, 'sent', 'Mail sent successfully.', $source_plugin );
	}
	return;
}
```

When result is retryable and `retry_after_seconds()` is greater than zero, pass that delay into `record_failure()`.

- [ ] **Step 6: Wire plugin dependencies**

In `Monte_Mail_Queue_Plugin::__construct()`, create:

```php
$this->throttle_window = new Monte_Mail_Queue_Throttle_Window( $this->settings, $this->repository );
$this->azure_client    = new Monte_Mail_Queue_Azure_Email_Client( $this->settings );
$this->worker          = new Monte_Mail_Queue_Worker( $this->settings, $this->repository, $this->interceptor, $this->throttle_window, $this->azure_client );
```

Add private properties and public accessors for throttle window and Azure client so admin can use them for dashboard usage and test-mail sending.

- [ ] **Step 7: Run tests and syntax**

Run:

```bash
php tests/run.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: all tests pass and all PHP files report no syntax errors.

- [ ] **Step 8: Commit**

Run:

```bash
git add includes/class-monte-mail-queue-worker.php includes/class-monte-mail-queue-plugin.php includes/class-monte-mail-queue-repository.php tests/WorkerTest.php tests/run.php
git commit --no-gpg-sign -m "feat: deliver queued mail through selected transport"
```

---

### Task 6: Add Admin UI, Test Mail Action, And Dashboard Usage

**Files:**
- Modify: `includes/class-monte-mail-queue-admin.php`
- Modify: `includes/class-monte-mail-queue-plugin.php`
- Modify: `assets/admin.css`
- Modify: `tests/SettingsTest.php`

**Interfaces:**
- Consumes: `Monte_Mail_Queue_Azure_Email_Client::send()`
- Consumes: `Monte_Mail_Queue_Throttle_Window::status()`
- Consumes: `Monte_Mail_Queue_Throttle_Window::record_accepted()`
- Produces POST action fields: `wmqt_test_mail_nonce`, `test_sender_domain`, `test_sender_username`, `test_recipient`, `test_subject`, `test_body`, `test_attachment`

- [ ] **Step 1: Add admin dependencies**

Modify `Monte_Mail_Queue_Admin` constructor to accept optional `$throttle_window` and `$azure_client` after installer:

```php
public function __construct( Monte_Mail_Queue_Settings $settings, Monte_Mail_Queue_Repository $repository, Monte_Mail_Queue_Installer $installer, $throttle_window = null, $azure_client = null ) {
	$this->settings        = $settings;
	$this->repository      = $repository;
	$this->installer       = $installer;
	$this->throttle_window = $throttle_window;
	$this->azure_client    = $azure_client;
}
```

Update `Monte_Mail_Queue_Plugin::admin()` to pass these dependencies.

- [ ] **Step 2: Render Azure settings section**

In `render_settings()`, after queue settings, render:

```php
echo '<h2>' . esc_html__( 'Azure Communication Email', 'monte-mail-queue-throttle' ) . '</h2>';
$this->render_checkbox_field( 'azure_email_enabled', __( 'Enable Azure Email transport', 'monte-mail-queue-throttle' ), $settings['azure_email_enabled'] ?? 0, __( 'When enabled, the queue worker sends through Azure Communication Services Email instead of wp_mail().', 'monte-mail-queue-throttle' ) );
$this->render_textarea_field( 'azure_connection_string', __( 'ACS connection string', 'monte-mail-queue-throttle' ), $settings['azure_connection_string'] ?? '', __( 'Paste the Azure Communication Services connection string.', 'monte-mail-queue-throttle' ) );
$this->render_textarea_field( 'azure_sender_domains', __( 'Verified sender domains', 'monte-mail-queue-throttle' ), $settings['azure_sender_domains'] ?? '', __( 'Enter one verified domain per line or comma-separated.', 'monte-mail-queue-throttle' ) );
$this->render_text_field( 'azure_sender_username', __( 'Default sender username', 'monte-mail-queue-throttle' ), $settings['azure_sender_username'] ?? 'DoNotReply' );
$this->render_text_field( 'azure_default_domain', __( 'Default sender domain', 'monte-mail-queue-throttle' ), $settings['azure_default_domain'] ?? '' );
$this->render_email_field( 'azure_reply_to', __( 'Reply-to email', 'monte-mail-queue-throttle' ), $settings['azure_reply_to'] ?? '' );
```

- [ ] **Step 3: Render test-mail panel**

Below the settings form, add a separate form:

```php
echo '<h2>' . esc_html__( 'Send test email', 'monte-mail-queue-throttle' ) . '</h2>';
echo '<form method="post" enctype="multipart/form-data" action="">';
wp_nonce_field( 'wmqt_send_test_mail', 'wmqt_test_mail_nonce' );
$this->render_sender_domain_select( $settings );
$this->render_text_field( 'test_sender_username', __( 'Sender email username', 'monte-mail-queue-throttle' ), $settings['azure_sender_username'] ?? 'DoNotReply' );
$this->render_email_field( 'test_recipient', __( 'Recipient email address', 'monte-mail-queue-throttle' ), '' );
$this->render_text_field( 'test_subject', __( 'Subject', 'monte-mail-queue-throttle' ), __( 'Test Email', 'monte-mail-queue-throttle' ) );
$this->render_textarea_field( 'test_body', __( 'Body', 'monte-mail-queue-throttle' ), __( 'Hello world via email.', 'monte-mail-queue-throttle' ) );
echo '<input type="file" name="test_attachment" id="test_attachment">';
submit_button( __( 'Send', 'monte-mail-queue-throttle' ), 'primary', 'wmqt_send_test_mail' );
echo '</form>';
```

The sender-domain select reads domains from `azure_sender_domains`.

- [ ] **Step 4: Handle test-mail submission**

At the top of `render_settings()`:

```php
if ( isset( $_POST['wmqt_test_mail_nonce'] ) ) {
	$this->send_test_mail();
}
```

Implement `send_test_mail()`:

- Check nonce `wmqt_send_test_mail`.
- Check `manage_options`.
- Build `$mail` with `to`, `subject`, `message`, `headers`, and optional uploaded attachment path.
- Check throttle status for selected transport.
- If throttled, log `throttled_minute` or `throttled_hour` with queue ID `0`.
- If Azure enabled, call Azure client with overrides for sender username and domain.
- If Azure disabled, call `wp_mail()` directly.
- On accepted send, record throttle window with queue ID `0`.
- Log success or retry or failure with queue ID `0`.
- Delete temporary uploaded test attachment when possible.

- [ ] **Step 5: Add dashboard usage cards**

In `render_dashboard()`, add cards for:

```php
Configured hour rate
Worker interval
Minute window
Hour window
Active transport
```

Use throttle window status for `Minute window` and `Hour window`, displayed as `used / limit`.

- [ ] **Step 6: Add log filters**

Add these events to `render_log_filter()` and `requested_event_type()`:

```php
'throttled_minute'
'throttled_hour'
'azure_send_accepted'
'test_sent'
'test_retry'
'test_failed'
```

- [ ] **Step 7: Add CSS for form layout**

In `assets/admin.css`, add focused rules:

```css
.wmqt-settings-section {
	margin-top: 22px;
}

.wmqt-test-mail {
	background: var(--wmqt-surface);
	border: 1px solid var(--wmqt-border);
	border-radius: 6px;
	margin-top: 24px;
	max-width: 760px;
	padding: 16px;
}

.wmqt-test-mail textarea {
	min-height: 120px;
	width: 100%;
}
```

- [ ] **Step 8: Run tests and syntax**

Run:

```bash
php tests/run.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: all tests pass and all PHP files report no syntax errors.

- [ ] **Step 9: Commit**

Run:

```bash
git add includes/class-monte-mail-queue-admin.php includes/class-monte-mail-queue-plugin.php assets/admin.css tests/SettingsTest.php
git commit --no-gpg-sign -m "feat: add azure settings and test mail"
```

---

### Task 7: Documentation, Version Bump, Package, And Final Verification

**Files:**
- Modify: `monte-mail-queue-throttle.php`
- Modify: `README.md`
- Modify: `monte-mail-queue-throttle.zip`

**Interfaces:**
- Consumes all previous tasks.
- Produces uploadable ZIP package with the plugin root folder.

- [ ] **Step 1: Bump plugin version**

Update `monte-mail-queue-throttle.php`:

```php
 * Version: 0.5.0
define( 'WMQT_VERSION', '0.5.0' );
```

- [ ] **Step 2: Update README**

Update README sections:

- Highlights includes hourly throttling and optional Azure Email delivery.
- Current Version is `0.5.0`.
- Default Behavior documents `rate_per_hour = 1500` and `worker_interval_minutes = 2`.
- Settings documents `Mails per hour`, `Worker interval minutes`, Azure connection string, sender domains, sender username, default sender domain, reply-to, and test mail.
- Architecture adds ACS delivery path:

```text
WP-Cron worker -> Azure Communication Services Email REST API
```

- FluentSMTP notes clarify that FluentSMTP is used only when Azure transport is disabled.

- [ ] **Step 3: Run full command verification**

Run:

```bash
php tests/run.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: all tests pass and all PHP files report no syntax errors.

- [ ] **Step 4: Rebuild upload ZIP**

Run:

```bash
rm -rf build
mkdir -p build/monte-mail-queue-throttle
rsync -a --exclude='.git' --exclude='build' --exclude='docs' --exclude='tests' ./ build/monte-mail-queue-throttle/
cd build && zip -r ../monte-mail-queue-throttle.zip monte-mail-queue-throttle
```

Expected: `monte-mail-queue-throttle.zip` exists.

- [ ] **Step 5: Inspect ZIP contents**

Run:

```bash
unzip -l monte-mail-queue-throttle.zip | sed -n '1,120p'
```

Expected: includes `monte-mail-queue-throttle/monte-mail-queue-throttle.php`, `includes/`, `assets/`, and `README.md`. Expected: does not include `.git`, `build`, `docs`, or `tests`.

- [ ] **Step 6: Final git review**

Run:

```bash
git status --short
git diff --stat HEAD
```

Expected: only intended files are modified.

- [ ] **Step 7: Commit**

Run:

```bash
git add monte-mail-queue-throttle.php README.md monte-mail-queue-throttle.zip
git commit --no-gpg-sign -m "docs: release azure mail throttling update"
```

---

## Self-Review

- Spec coverage: Tasks cover hourly throttle, configurable worker cadence, send-window storage, Azure connection string, manual sender domains, ACS REST sending, provider retry feedback, settings UI, test mail, logs, dashboard, docs, ZIP packaging, and PHP syntax verification.
- Placeholder scan: No placeholder markers remain in task steps.
- Type consistency: Settings keys, class names, and method names are stable across tasks. Delivery result factories use `accepted_result()`, `retry_result()`, and `failed_result()` so instance methods can use `accepted()`, `retryable()`, and `failed()` without name conflicts.
- PHP compatibility note: New production snippets avoid adding `void` return types. Worker test doubles keep `void` only where needed to match existing repository method signatures in this codebase.
