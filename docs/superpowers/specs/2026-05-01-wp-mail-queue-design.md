# WP Mail Queue Plugin Design

## Goal

Build an uploadable WordPress plugin that intercepts mails sent through `wp_mail()`, stores them in a queue, and sends them later at a configurable throttled rate. The default rate is 25 mails per minute. The queue worker runs via WP-Cron every 120 seconds, matching the hosting environment where GoDaddy Managed WordPress triggers cron roughly every two minutes.

Version 0.2.0 declares WordPress and PHP requirements in the plugin header and keeps the uploadable ZIP as the release artifact.

The target delivery architecture is:

`WordPress/plugin -> WP Mail Queue Plugin -> throttled wp_mail() replay -> FluentSMTP -> mail provider`

## Scope

The plugin queues all `wp_mail()` calls by default. It also includes optional source-plugin filtering so the site owner can later switch to queue only mails originating from specific plugins, such as `email-users` or `send-users-email`.

The plugin includes four admin views:

- Dashboard
- Settings
- Queue
- Logs

## Mail Interception

The plugin uses the `pre_wp_mail` filter to intercept outgoing messages before WordPress hands them to PHPMailer. It stores the mail arguments in a custom queue table and returns `true`, which tells WordPress the mail was accepted.

When the queue worker sends queued messages, it temporarily bypasses its own interception logic and calls `wp_mail()` again. This allows FluentSMTP to handle the actual transport normally.

## Queue Processing

The worker runs on a custom WP-Cron schedule every 120 seconds.

The effective batch size is calculated from the configured mails-per-minute rate:

`batch_size = rate_per_minute * 2`

With the default rate of 25 mails per minute, each two-minute cron run sends up to 50 queued mails.

Each queue item has a status:

- `queued`
- `processing`
- `sent`
- `failed`

The worker locks each item before sending by moving it to `processing`. Successful sends become `sent` and receive a sent timestamp. Failed sends become `failed` or return to `queued` depending on retry count.

If a worker request is killed after claiming rows, the next worker run recovers `processing` rows older than 15 minutes back to `queued` without incrementing attempts. The stale threshold is evaluated in SQL relative to the same WordPress-local datetime format stored in `updated_at`.

## Retry And Error Handling

The plugin stores retry count, max retry count, last error, and timestamps for each queue item.

Default max retries is 3. If a send fails and retries remain, the item is returned to `queued`. If max retries is reached, the item remains `failed`.

Each attempt writes a log entry, including successful sends and failures.

The worker also prunes log rows older than the configured log retention setting. Default retention is 30 days.

## Source Plugin Detection

The plugin can detect the likely origin plugin of a mail by inspecting `debug_backtrace()` during interception and looking for paths under `wp-content/plugins/{plugin-slug}/`.

Default behavior is to queue all mails.

An optional setting allows queueing only selected plugin slugs. In that mode, if the source plugin cannot be detected or is not in the allowlist, the plugin bypasses queue storage and lets the original `wp_mail()` flow continue immediately.

Known transport plugins such as `fluent-smtp` are ignored during source detection so the origin can resolve to the plugin that initiated the mail, such as `send-users-email`.

This detection is intentionally treated as a pragmatic heuristic. It is good enough for targeting bulk-mail plugins such as `email-users` and `send-users-email`, but it is not a formal WordPress mail-origin API or a security boundary.

## Data Storage

The plugin uses two custom database tables:

### Queue Table

Stores the queued mail payload and current processing state.

Main fields:

- ID
- recipients
- subject
- message body
- headers
- attachments
- source plugin slug
- status
- attempts
- max attempts
- last error
- queued timestamp
- updated timestamp
- sent timestamp

Mail arguments are stored as JSON where appropriate.

Nullable datetime fields are omitted from inserts until they have real values, which avoids strict-mode MySQL rejecting empty datetime strings. If JSON encoding of recipients, headers, or attachments fails, the plugin logs the encoding failure and lets the original `wp_mail()` flow continue instead of storing a broken queue payload.

### Log Table

Stores event history for queue processing.

Main fields:

- ID
- queue item ID
- event type
- message
- source plugin slug
- created timestamp

## Admin Dashboard

The plugin start page shows operational status:

- queued count
- sent count
- failed count
- processing count
- configured rate
- estimated mails per cron run
- next scheduled cron timestamp, if available

The dashboard links to Settings, Queue, and Logs.

## Settings View

Settings include:

- mails per minute, default 25
- max retries, default 3
- queue mode: all mails or selected plugin slugs
- allowed plugin slugs, default includes `email-users,send-users-email` but is only used in selected-plugin mode
- log retention days, default 30

The cron interval is fixed at 120 seconds for this version.

## Queue View

The Queue view lists active `queued` and `processing` items by default with:

- ID
- recipients
- subject
- source plugin
- status
- attempts
- last error
- queued timestamp
- sent timestamp

It supports status filtering and paginates results for large queues.

## Logs View

The Logs view lists all send attempts and operational events by default with:

- timestamp
- event type
- queue item ID
- source plugin
- recipients
- subject
- queue status
- attempts
- last error
- queued timestamp
- sent timestamp
- message

It supports event filtering and paginates results for large log tables. Sent and failed delivery history is expected to be reviewed here instead of the default Queue view.

## Activation And Deactivation

On activation, the plugin creates or updates its custom tables and schedules the WP-Cron event.

On deactivation, it clears the scheduled WP-Cron event. It does not delete queue or log tables.

On uninstall, the plugin removes its option, queue table, and log table.

## Verification

Implementation should verify:

- plugin files package as an uploadable WordPress plugin
- activation creates tables
- settings can be saved
- `wp_mail()` calls are queued by default
- cron worker sends at most `rate_per_minute * 2` messages per run
- worker replay bypasses interception so FluentSMTP can send
- successful sends create log entries
- failed sends retry and eventually become failed
- selected-plugin mode queues only allowed source plugins
- queue insert works under strict-mode MySQL
- stale processing rows recover after timeout
- log retention is enforced by worker runs
- queue and log tables paginate in admin
