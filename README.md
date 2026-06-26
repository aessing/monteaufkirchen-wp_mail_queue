![Monte Mail Queue Throttle comic illustration](assets/monte-mail-queue-comic-banner.jpg)

# Monte Mail Queue Throttle

A WordPress plugin that intercepts `wp_mail()` calls, queues eligible messages, and sends them later at a controlled pace through the configured transport.

Built for WordPress sites that send bulk mail through providers with strict rate limits, with optional Azure Communication Services Email delivery when direct provider transport is preferred.

## Highlights

| Area | What it does |
| --- | --- |
| Queueing | Captures WordPress `wp_mail()` calls before immediate delivery. |
| Throttling | Enforces an hourly send limit, defaulting to 1500 mails per hour. |
| Cron cadence | Runs through a configurable WP-Cron schedule, defaulting to every 2 minutes. |
| Azure delivery | Can send queued mail through Azure Communication Services Email REST API. |
| FluentSMTP | Continues using FluentSMTP only when Azure transport is disabled. |
| Source filtering | Can queue all mail or only mail detected from selected plugin slugs such as `send-users-email`. |
| Admin UI | Includes Dashboard, Settings, Queue, and Logs views. |
| Reporting | Shows status cards, a stacked 30-day mail activity chart, paginated queue rows, and log history. |
| Recovery | Requeues stale `processing` jobs after 15 minutes if a cron run is interrupted. |
| Retention | Prunes old logs and completed queue rows using the configured retention window. |

## Current Version

`0.5.0`

This release adds hourly throttling, configurable worker cadence, Azure Communication Services Email delivery, test-mail support, send-window tracking, updated dashboard usage cards, and the final upload package refresh.

## Architecture

Default WordPress mail flow:

```text
WordPress or plugin -> wp_mail() -> FluentSMTP -> SMTP provider
```

With Monte Mail Queue Throttle:

```text
WordPress or plugin -> wp_mail() -> Monte Mail Queue Throttle -> queued database row
WP-Cron worker -> Azure Communication Services Email REST API
WP-Cron worker -> wp_mail() replay -> FluentSMTP -> SMTP provider
```

When Azure transport is enabled, the worker sends directly through the Azure Communication Services Email REST API. When Azure transport is disabled, the plugin enables an internal bypass so the worker's own `wp_mail()` replay is not queued again.

## Installation

1. Upload `monte-mail-queue-throttle.zip` in WordPress under **Plugins > Add New > Upload Plugin**.
2. Activate **Monte Mail Queue Throttle**.
3. Open **Mail Queue > Dashboard** and confirm the worker schedule is visible.
4. Open **Mail Queue > Settings** and review the send rate, worker interval, retry count, source mode, Azure transport settings, and retention settings.
5. If Azure delivery is enabled, add the ACS connection string and verified sender settings before sending mail.

Activation creates the queue and log tables, stores default settings when needed, and schedules the queue worker using the configured interval.

## Default Behavior

Out of the box, the plugin:

- Queues all eligible `wp_mail()` calls.
- Processes the queue every 2 minutes with WP-Cron.
- Uses `rate_per_hour = 1500`.
- Uses `worker_interval_minutes = 2`.
- Calculates the per-run batch size from the hourly limit and worker interval.
- Retries failed messages up to 3 total attempts.
- Keeps logs for 30 days and completed sent queue rows for 180 days by default; failed queue rows are retained for at least 365 days.
- Uses exponential retry backoff before a failed message is eligible for another send attempt.
- Uses `email-users,send-users-email` as the default allowed plugin slug list when selected-plugin queueing is enabled.
- Falls back to normal immediate delivery if queue insertion fails.

## Admin Screens

### Dashboard

The plugin start screen gives administrators a clear operational overview:

- Active queue counts.
- Failed and sent totals.
- Configured mails-per-hour rate.
- Configured worker interval and calculated batch size per run.
- Next scheduled worker run.
- Stacked 30-day activity chart for queued volume plus `processing`, `failed`, and `sent` outcomes.
- Active queue preview with at least 10 recent queue rows when available.

### Settings

Configure:

- Mails per hour.
- Worker interval minutes.
- Maximum attempts per message.
- Queue mode: all sources or selected plugins.
- Allowed plugin slugs.
- Log retention in days.
- Completed queue retention in days.
- Azure connection string.
- Verified sender domains.
- Default sender username.
- Default sender domain.
- Reply-to email.
- Test mail.

Settings are stored in the `wmqt_settings` option.

### Queue

The queue view focuses on actionable work by default:

- Shows only `queued` and `processing` messages initially.
- Supports status filtering for other queue states.
- Uses pagination for large queues.
- Shows recipients, subject, source plugin, status, attempts, last error, queued time, and sent time.

### Logs

The logs view is built for audit and diagnosis:

- Shows all events by default.
- Supports event filtering.
- Uses pagination for large log tables.
- Keeps the same related message context as the queue view.
- Includes events such as enqueue, claim, send success, retry, failure, recovery, missing attachments, and encode failures.

## FluentSMTP Notes

Monte Mail Queue Throttle does not replace FluentSMTP when Azure transport is disabled. In that mode, it controls when WordPress sends, then hands delivery back to the normal `wp_mail()` pipeline.

Configure FluentSMTP first when you want WordPress-based delivery. If Azure transport is enabled, FluentSMTP is bypassed and the worker sends through Azure Communication Services Email instead. Attachments are stored and replayed as their original local WordPress file paths, so queued payloads should be treated as trusted internal mail data.

## Source Plugin Filtering

The plugin can queue either all mail or only mail from selected plugin slugs.

Source detection uses the PHP call stack and looks for files under:

```text
wp-content/plugins/{plugin-slug}/
```

Known mail transport plugins such as `fluent-smtp` are ignored during detection so the original sender, for example `send-users-email`, can be recognized instead.

This detection is a throttling convenience, not a security boundary. Developers can customize ignored transport plugin slugs with:

```php
add_filter( 'wmqt_ignored_source_plugin_slugs', function ( array $slugs ): array {
	$slugs[] = 'my-transport-plugin';
	return $slugs;
} );
```

## GoDaddy And WP-Cron

This plugin is designed to work with managed WordPress hosting where a host or external cron calls WordPress regularly. Set **Worker interval minutes** to match the actual cron cadence, for example `1` when WP-Cron runs every minute.

WP-Cron normally depends on site traffic. For reliable delivery on quiet sites, make sure the host or a real server cron calls `wp-cron.php` regularly. If `DISABLE_WP_CRON` is enabled, an external cron runner is required.

If a cron request is interrupted after claiming messages, the next worker run recovers stale `processing` rows older than 15 minutes and returns them to `queued`.

## Database Tables

The plugin creates two custom tables using the site's WordPress table prefix:

- `{prefix}wmqt_queue`
- `{prefix}wmqt_logs`

Deactivation clears the scheduled worker hook but keeps settings, queue rows, logs, and tables. Worker runs prune old sent queue rows according to completed queue retention; failed queue rows are kept for at least 365 days. Deleting the plugin through WordPress runs `uninstall.php`, which removes the plugin options, queue table, and log table.

## Upload Package

The repository includes an upload-ready ZIP:

```text
monte-mail-queue-throttle.zip
```

Upload that file directly through the WordPress plugin installer.

## Requirements

- WordPress 5.8 or newer.
- PHP 7.0 or newer.
- A working WordPress mail transport, such as FluentSMTP.
- WP-Cron or an external cron runner.

## Author

Created by [Andre Essing](https://www.linkedin.com/in/aessing/).
