# Monte Mail Queue Throttle

A WordPress plugin that intercepts `wp_mail()` calls, queues eligible messages, and sends them later at a controlled pace through the site's normal mail transport.

Built for WordPress sites that send bulk mail through providers with strict rate limits, while keeping FluentSMTP or another SMTP plugin in the delivery path.

![Monte Mail Queue Throttle comic illustration](assets/monte-mail-queue-comic.jpg)

## Highlights

| Area | What it does |
| --- | --- |
| Queueing | Captures WordPress `wp_mail()` calls before immediate delivery. |
| Throttling | Sends at a configurable rate, defaulting to 25 mails per minute. |
| Cron cadence | Runs through a custom WP-Cron schedule every 120 seconds. |
| FluentSMTP | Replays queued mail back through `wp_mail()`, so FluentSMTP still sends the final message. |
| Source filtering | Can queue all mail or only mail detected from selected plugin slugs such as `send-users-email`. |
| Admin UI | Includes Dashboard, Settings, Queue, and Logs views. |
| Reporting | Shows status cards, a stacked 30-day mail volume chart, paginated queue rows, and log history. |
| Recovery | Requeues stale `processing` jobs after 15 minutes if a cron run is interrupted. |

## Current Version

`0.3.0`

This release adds the polished admin dashboard, stacked 30-day status chart, improved queue and log tables, source plugin detection fixes, stricter database handling, log retention cleanup, and upload-ready packaging.

## Architecture

Default WordPress mail flow:

```text
WordPress or plugin -> wp_mail() -> FluentSMTP -> SMTP provider
```

With Monte Mail Queue Throttle:

```text
WordPress or plugin -> wp_mail() -> Monte Mail Queue Throttle -> queued database row
WP-Cron worker -> wp_mail() replay -> FluentSMTP -> SMTP provider
```

During replay, the plugin enables an internal bypass so the worker's own `wp_mail()` call is not queued again.

## Installation

1. Upload `monte-mail-queue-throttle.zip` in WordPress under **Plugins > Add New > Upload Plugin**.
2. Activate **Monte Mail Queue Throttle**.
3. Open **Mail Queue > Dashboard** and confirm the worker schedule is visible.
4. Open **Mail Queue > Settings** and review the send rate, retry count, source mode, and log retention.

Activation creates the queue and log tables, stores default settings when needed, and schedules the two-minute queue worker.

## Default Behavior

Out of the box, the plugin:

- Queues all eligible `wp_mail()` calls.
- Processes the queue every two minutes with WP-Cron.
- Sends up to 25 mails per minute, which means up to 50 messages per worker run.
- Retries failed messages up to 3 total attempts.
- Keeps logs for 30 days by default.
- Uses `email-users,send-users-email` as the default allowed plugin slug list when selected-plugin queueing is enabled.
- Falls back to normal immediate delivery if queue insertion fails.

## Admin Screens

### Dashboard

The plugin start screen gives administrators a clear operational overview:

- Active queue counts.
- Failed and sent totals.
- Configured mails-per-minute rate.
- Calculated batch size per two-minute cron run.
- Next scheduled worker run.
- Stacked 30-day chart for `queued`, `processing`, `failed`, and `sent`.
- Active queue preview with at least 10 recent queue rows when available.

### Settings

Configure:

- Mails per minute.
- Maximum attempts per message.
- Queue mode: all sources or selected plugins.
- Allowed plugin slugs.
- Log retention in days.

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
- Includes events such as enqueue, claim, send success, retry, failure, recovery, and encode failures.

## FluentSMTP Notes

Monte Mail Queue Throttle does not replace FluentSMTP. It controls when WordPress sends, then hands delivery back to the normal `wp_mail()` pipeline.

Configure FluentSMTP first, then use this plugin to slow down the rate at which queued messages reach FluentSMTP. Attachments are stored and replayed as their original local WordPress file paths, so queued payloads should be treated as trusted internal mail data.

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

This plugin is designed to work with GoDaddy Managed WordPress style hosting where a system cron calls WordPress roughly every two minutes.

WP-Cron normally depends on site traffic. For reliable delivery on quiet sites, make sure the host or a real server cron calls `wp-cron.php` regularly. If `DISABLE_WP_CRON` is enabled, an external cron runner is required.

If a cron request is interrupted after claiming messages, the next worker run recovers stale `processing` rows older than 15 minutes and returns them to `queued`.

## Database Tables

The plugin creates two custom tables using the site's WordPress table prefix:

- `{prefix}wmqt_queue`
- `{prefix}wmqt_logs`

Deactivation clears the scheduled worker hook but keeps settings, queue rows, logs, and tables. Deleting the plugin through WordPress runs `uninstall.php`, which removes the option, queue table, and log table.

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
