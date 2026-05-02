# WP Mail Queue Throttle

WP Mail Queue Throttle intercepts WordPress `wp_mail()` calls, stores eligible messages in a database-backed queue, and replays them through the active WordPress mail transport at a controlled rate.

## Installation

1. Upload `wp-mail-queue-throttle.zip` through **Plugins > Add New > Upload Plugin**, or extract the `wp-mail-queue-throttle` folder into `wp-content/plugins/`.
2. Activate **WP Mail Queue Throttle** from the WordPress Plugins screen.
3. Confirm the queue worker is scheduled from **Mail Queue > Dashboard**.
4. Review settings under **Mail Queue > Settings** before sending a large campaign.

Activation creates the queue and log tables, stores default settings when no settings exist yet, and schedules the queue processor.

## Default Behavior

By default, the plugin:

- Queues all `wp_mail()` calls.
- Processes the queue every two minutes with WP-Cron.
- Sends up to 25 mails per minute, which means up to 50 queued messages per two-minute worker run.
- Retries failed messages up to 3 total attempts.
- Keeps log entries visible in the admin log view and prunes old log rows during worker runs, with a default log retention setting of 30 days.
- Uses `email-users,send-users-email` as the default selected plugin slug list for sites that switch from all-source queueing to selected-plugin queueing.

When a message is queued successfully, the original `wp_mail()` call is short-circuited so it is not sent immediately. If queue insertion fails, normal `wp_mail()` delivery is allowed to continue.

## GoDaddy And WP-Cron Assumptions

This plugin is designed for hosts where bulk mail needs to be slowed down to avoid provider throttling, including GoDaddy-style shared hosting environments. It relies on WordPress WP-Cron for queue processing.

WP-Cron only runs when the site receives traffic unless a real server cron calls `wp-cron.php`. For production reliability, especially on low-traffic sites, configure a system cron or hosting control panel cron to request `wp-cron.php` regularly. The plugin registers a custom two-minute schedule and processes a batch on the `wmqt_process_queue` hook.

If WP-Cron is disabled with `DISABLE_WP_CRON`, an external cron runner is required or queued mail will not be processed.

If a WP-Cron request times out or is killed after claiming messages, the next worker run recovers processing locks older than 15 minutes by returning those messages to the queued state without incrementing attempts.

## FluentSMTP Integration

The plugin replays queued messages by calling WordPress `wp_mail()` during the queue worker run. That means it works with FluentSMTP and similar SMTP plugins through the normal WordPress mail pipeline.

Install and configure FluentSMTP as the active mail transport before relying on the queue. Queued messages keep their original recipients, subject, message body, headers, and attachments, then are sent later through the configured `wp_mail()` transport. Attachments are replayed as the original WordPress file paths, so treat queued payloads as trusted internal mail data. The plugin enables an internal bypass during replay so its own worker sends are not queued again.

## Source Plugin Filtering

The plugin can queue mail from all sources or only selected source plugins.

- **All sources**: every eligible `wp_mail()` call is queued.
- **Selected plugins**: only calls detected from configured plugin slugs are queued.

Source detection uses the PHP call stack and looks for files under `wp-content/plugins/{plugin-slug}/`. Known transport plugins such as `fluent-smtp` are skipped so the detected source remains the plugin that initiated the mail, such as `send-users-email`. Calls that cannot be matched to a plugin slug are not queued when selected-plugin mode is enabled. Configure selected slugs as a comma-separated list in **Mail Queue > Settings**. This is a throttling heuristic, not a security boundary.

Developers can customize ignored transport slugs with the `wmqt_ignored_source_plugin_slugs` filter.

## Author

Created by [Andre Essing](https://www.linkedin.com/in/aessing/).

## Settings And Admin Pages

The plugin adds a top-level **Mail Queue** admin menu for administrators with `manage_options`.

- **Dashboard**: shows queue counts, configured send rate, per-run batch limit, the next scheduled cron run, a stacked 30-day status chart, and a 10-row active queue preview.
- **Settings**: configures mails per minute, max retries, queue mode, allowed plugin slugs, and log retention days.
- **Queue**: lists active queued and processing messages by default, with recipients, subject, source plugin, status, attempts, errors, queued time, sent time, filtering, and pagination.
- **Logs**: lists all queue events by default, with filters, pagination, and related mail details including recipients, subject, source plugin, queue status, attempts, errors, queued time, and sent time.

Settings are stored in the `wmqt_settings` option.

Max retry attempts are stored on each queue item when it is created. Changing the setting later affects newly queued mail, not already queued rows.

## Deactivation Behavior

Deactivation clears the scheduled queue processing hook. It does not delete plugin settings, queue rows, log rows, or database tables. Reactivating the plugin recreates or updates the required tables if needed and schedules processing again.

Deleting the plugin through WordPress runs `uninstall.php`, which removes the plugin option, queue table, and log table.
