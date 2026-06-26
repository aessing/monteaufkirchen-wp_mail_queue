# Azure Email And Hourly Throttle Design

## Goal

Add two capabilities to Monte Mail Queue Throttle:

- A second throttle limit for mails per hour, alongside the existing mails per minute setting.
- Optional direct delivery through Azure Communication Services Email REST API, while preserving the existing `wp_mail()` replay path for FluentSMTP and other transport plugins.

Version target is `0.5.0`.

## Scope

This design keeps Azure sender configuration manual for v1. The plugin stores verified sender domains and a sender username, then builds the sender address used by Azure Email. It does not call Azure Resource Manager to discover domains.

The feature covers:

- Settings for mails per minute and mails per hour.
- A configurable worker interval so the old `rate_per_minute * 2` batch sizing can match the real WP-Cron cadence.
- A persistent throttle window so limits are enforced across WP-Cron runs.
- A transport switch between existing `wp_mail()` delivery and ACS Email REST delivery.
- ACS settings needed for REST API delivery.
- A settings-page test-mail form modeled after the Azure portal flow.
- Queue, log, and README updates that make the new behavior visible.

Out of scope for v1:

- Azure Resource Manager domain discovery.
- OAuth or managed identity auth.
- Live mailbox delivery tracking after Azure accepts the send operation.
- A separate screen for Azure templates or reusable campaigns.

## Current Behavior

The current worker runs every two minutes and computes a batch size from:

```text
rate_per_minute * 2
```

That keeps a rough send pace when WP-Cron runs regularly, but it does not enforce a true rolling minute window and it has no hourly cap. Delivery currently replays each queue item through `wp_mail()` with interception bypassed, which lets FluentSMTP or another configured WordPress mail transport send the final message.

The `2` multiplier assumes the plugin worker runs every two minutes. Some hosts call `wp-cron.php` every minute, so the worker interval and batch-size multiplier need to be configurable instead of hard-coded.

## Chosen Approach

Use a small send-window table to record accepted sends. Before every queued send, the worker counts recent accepted sends in the last rolling minute and last rolling hour. If either limit is reached, the worker stops processing and leaves remaining queue rows in `queued` status for the next cron run.

Use a new transport abstraction:

- `wp_mail`, the existing behavior.
- `azure_communication_email`, a REST client that sends through ACS Email when enabled.

The transport setting controls only worker replay and test mails. Interception and queue storage stay the same.

## Throttle Model

Settings:

- `rate_per_minute`, default `25`, minimum `1`.
- `rate_per_hour`, default `1500`, minimum `1`.
- `worker_interval_minutes`, default `2`, minimum `1`, maximum `60`.

The default hourly value equals `25 * 60`, which preserves current capacity unless the admin changes it.

The worker interval controls the custom WP-Cron schedule and the maximum rows a worker run may inspect:

```text
max_items_per_run = rate_per_minute * worker_interval_minutes
```

For the target hosting setup, `worker_interval_minutes` should be set to `1` because `wp-cron.php` is called every minute. Existing installs keep the default value `2` until an administrator changes it.

The rolling throttle is still the hard delivery limit. If the worker interval is greater than one minute, the worker does not burst through the minute limit and does not sleep to fill later minutes in the same request. It stops when the current rolling minute or hour limit is reached and continues on the next scheduled worker run.

New table:

```text
{prefix}wmqt_send_windows
```

Columns:

- `id`
- `queue_id`
- `transport`
- `accepted_at`
- `provider_message_id`

Indexes:

- `accepted_at`
- `transport, accepted_at`
- `queue_id`

The worker checks:

```text
COUNT(*) WHERE accepted_at >= now - 60 seconds
COUNT(*) WHERE accepted_at >= now - 3600 seconds
```

Only accepted sends are recorded. Failed attempts do not consume the throttle window unless the provider accepted the send.

If the minute limit is reached, the worker logs a throttle event and stops. If the hour limit is reached, it logs the hour throttle event and stops. The worker does not sleep inside WP-Cron.

Old send-window rows are pruned by worker runs. Keeping 48 hours is enough for throttling and short audit context.

## Azure Configuration

Settings add an Azure section:

- Enable Azure Communication Email transport.
- ACS connection string copied from Azure.
- Parsed ACS endpoint, shown read-only after save when possible.
- Verified sender domains, one per line or comma-separated.
- Default sender username, for example `DoNotReply`.
- Default sender domain, selected from the verified domains.
- Optional reply-to address.

The full sender address is:

```text
{sender_username}@{selected_domain}
```

The plugin stores the access key in WordPress options because the plugin already uses the options table for settings. The setting is editable only by administrators with `manage_options`.

The connection string is the primary admin input. Internally the client parses `endpoint` and `accesskey` from it for request signing. If the connection string cannot be parsed, Azure delivery is treated as misconfigured and the worker records a retryable configuration error instead of calling Azure.

## Azure REST Client

The ACS client sends with the Email REST API:

```text
POST {endpoint}/emails:send?api-version=2023-03-31
```

The request body maps queue payloads to Azure Email fields:

- `senderAddress` from configured sender username and domain.
- `recipients.to` from queued recipients.
- `content.subject` from queued subject.
- `content.plainText` or `content.html` from queued message and headers.
- `attachments` from queued attachment paths.
- `replyTo` from setting when configured.

The client signs requests with ACS HMAC authentication using the endpoint and access key parsed from the configured connection string. It sends requests through WordPress HTTP APIs, not cURL directly, so hosting proxies and WordPress filters still apply.

HTTP `202` is treated as accepted and records a send-window row. If Azure returns an operation location or operation ID, the plugin stores it in the log and send-window row as provider feedback.

The queue item is marked `sent` when Azure accepts the API request. This matches the existing `wp_mail()` behavior, where success means the configured transport accepted the message, not that the recipient mailbox confirmed delivery.

## Azure Error Handling

Retryable responses:

- `429`
- `500`
- `502`
- `503`
- `504`
- WordPress HTTP transport failures

When Azure includes retry feedback, the worker uses it:

- `Retry-After`
- `retry-after`
- `x-ms-retry-after-ms`

If Azure provides a retry delay, the queue item returns to `queued` with that delay. If not, the existing exponential retry backoff is used.

Permanent validation-style responses, such as malformed recipients or missing sender configuration, are recorded as failures according to the existing max-attempts behavior.

Logs should include:

- `sent` for accepted sends.
- `retry` for retryable ACS failures.
- `failed` for final failures.
- `throttled_minute` when the minute window is full.
- `throttled_hour` when the hour window is full.
- `azure_send_accepted` with provider operation feedback when available.

## Test Mail Form

The settings page adds a separate test-mail panel for administrators.

Fields:

- Send email from domain, selected from the configured verified domains.
- Sender email username.
- Recipient email address or addresses.
- Subject.
- Body.
- Optional attachment upload.

The test action uses the selected transport:

- If Azure is enabled, send through ACS Email REST API.
- If Azure is disabled, send through the current `wp_mail()` transport.

The test mail is sent immediately and is not added to the queue. It checks the same throttle window before sending and records accepted sends with queue ID `0`, because test mails are real provider sends. It also records a log entry with queue ID `0` so the outcome is visible in Logs.

Uploaded test attachments are handled through WordPress upload APIs and deleted after the send attempt when possible.

## Admin UI Changes

Settings page:

- Keep existing queue and retention settings.
- Add `Mails per hour` beside `Mails per minute`.
- Add `Worker interval minutes` near the throttle settings, with help text explaining that `1` matches a real one-minute WP-Cron runner.
- Add an Azure delivery section with enable checkbox and connection fields.
- Add a test-mail section below settings.

Dashboard:

- Show configured minute rate.
- Show configured hour rate.
- Show configured worker interval.
- Show current rolling minute usage.
- Show current rolling hour usage.
- Show active transport.

Logs:

- Allow filter values for the new throttle and Azure events.
- Show provider feedback in the message column.

## Data Flow

Queueing remains unchanged:

```text
WordPress or plugin -> wp_mail() -> interceptor -> queue table
```

Worker delivery with existing transport:

```text
worker -> throttle check -> wp_mail() replay -> record accepted send -> mark sent
```

Worker delivery with ACS:

```text
worker -> throttle check -> ACS REST request -> record accepted send -> mark sent
```

Test mail:

```text
settings form -> selected transport -> log result with queue ID 0
```

## Files Expected To Change

- `monte-mail-queue-throttle.php`
- `includes/class-monte-mail-queue-settings.php`
- `includes/class-monte-mail-queue-installer.php`
- `includes/class-monte-mail-queue-repository.php`
- `includes/class-monte-mail-queue-worker.php`
- `includes/class-monte-mail-queue-admin.php`
- `includes/class-monte-mail-queue-plugin.php`
- `assets/admin.css`
- `README.md`
- `monte-mail-queue-throttle.zip`

Expected new files:

- `includes/class-monte-mail-queue-azure-email-client.php`
- `includes/class-monte-mail-queue-delivery-result.php`
- `includes/class-monte-mail-queue-throttle-window.php`

## Compatibility

The plugin still declares PHP 7.0 support. New code must avoid PHP features newer than PHP 7.0.

The Azure REST client must not require Composer packages. It uses WordPress core functions and PHP built-ins only.

Existing installs should keep their current behavior after update. Azure delivery is disabled by default, and the default hourly cap preserves the existing default rate.

When `worker_interval_minutes` changes, the plugin reschedules the worker hook so the configured interval takes effect without requiring deactivate and reactivate.

## Verification

Automated or command-level checks:

- PHP syntax check with `php -l` for every PHP file.
- Test-first coverage for settings sanitization, throttle-window decisions, ACS request signing and body mapping where practical outside WordPress.
- ZIP inspection to confirm uploadable plugin contents.

Manual WordPress checks when an environment is available:

- Existing `wp_mail()` replay still works with Azure disabled.
- Minute throttle stops sends when the rolling minute cap is reached.
- Hour throttle stops sends when the rolling hour cap is reached.
- Worker interval can be set to `1` and the cron schedule is rescheduled to a one-minute cadence.
- ACS settings save correctly.
- ACS test mail sends with configured sender and recipient.
- ACS test mail respects and updates the throttle window.
- Queue worker sends through ACS when enabled.
- Retryable ACS failures return items to queued with the provider retry delay when present.
- Logs show throttle events and provider feedback.

## Sources

- Azure Communication Services Email JavaScript quickstart:
  `https://learn.microsoft.com/en-us/azure/communication-services/quickstarts/email/send-email?tabs=windows%2Cconnection-string%2Csend-email-and-get-status-async%2Casync-client&pivots=programming-language-javascript`
- Azure Communication Email REST API specification:
  `https://raw.githubusercontent.com/Azure/azure-rest-api-specs/main/specification/communication/data-plane/Email/stable/2023-03-31/CommunicationServicesEmail.json`
