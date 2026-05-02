# Monte Mail Queue Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an uploadable WordPress plugin that queues `wp_mail()` calls, sends them through FluentSMTP at a configurable throttled rate, and provides Dashboard, Settings, Queue, and Logs admin views.

**Architecture:** The plugin intercepts `wp_mail()` with `pre_wp_mail`, stores mail payloads in custom tables, and replays queued messages from a 120-second WP-Cron worker. During replay it bypasses its own interceptor so FluentSMTP receives the normal `wp_mail()` call and handles transport.

**Tech Stack:** WordPress plugin PHP, custom `$wpdb` tables, WP-Cron, Settings API-style nonce handling, admin menu pages, `dbDelta()`, PHP syntax checks.

---

## File Structure

- `monte-mail-queue-throttle.php`: Plugin header, constants, activation/deactivation hooks, bootstrap.
- `includes/class-monte-mail-queue-plugin.php`: Main coordinator that wires hooks and dependencies.
- `includes/class-monte-mail-queue-installer.php`: Database schema and cron schedule lifecycle.
- `includes/class-monte-mail-queue-settings.php`: Option defaults, reads, validation, and updates.
- `includes/class-monte-mail-queue-source-detector.php`: Source plugin detection via `debug_backtrace()`.
- `includes/class-monte-mail-queue-repository.php`: Queue and log table persistence.
- `includes/class-monte-mail-queue-interceptor.php`: `pre_wp_mail` handling and queue bypass during replay.
- `includes/class-monte-mail-queue-worker.php`: Cron batch processing, retry handling, and replay.
- `includes/class-monte-mail-queue-admin.php`: Admin menu, routing, view rendering, actions.
- `assets/admin.css`: Minimal admin styling.
- `README.md`: Installation and behavior notes.

## Tasks

### Task 1: Bootstrap And Installer

**Files:**
- Create: `monte-mail-queue-throttle.php`
- Create: `includes/class-monte-mail-queue-installer.php`
- Create: `includes/class-monte-mail-queue-plugin.php`
- Create: `includes/class-monte-mail-queue-settings.php`

- [ ] **Step 1: Create plugin bootstrap**

Create `monte-mail-queue-throttle.php` with plugin metadata, constants, class includes, activation/deactivation hooks, and `plugins_loaded` bootstrap.

- [ ] **Step 2: Create settings class**

Create `Monte_Mail_Queue_Settings` with defaults for `rate_per_minute = 25`, `max_attempts = 3`, `queue_mode = all`, `allowed_plugins = email-users`, `log_retention_days = 30`, and `queue_retention_days = 180`.

- [ ] **Step 3: Create installer class**

Create two custom tables using `dbDelta()`:

- `{$wpdb->prefix}wmqt_queue`
- `{$wpdb->prefix}wmqt_logs`

Queue schema includes `next_attempt_at`, `status_next_attempt`, and `status_updated` for retry scheduling and stale-lock recovery. Register cron schedule `wmqt_two_minutes` with interval `120`, schedule event `wmqt_process_queue`, clear it on deactivation, and run a DB-version upgrade check during admin and WP-Cron requests for ZIP updates.

- [ ] **Step 4: Create plugin coordinator**

Create `Monte_Mail_Queue_Plugin` with `init()` that wires cron schedule registration and leaves room for later interceptor, worker, and admin classes.

- [ ] **Step 5: Verify PHP syntax**

Run:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: every file reports `No syntax errors detected`.

- [ ] **Step 6: Commit**

Run:

```bash
git add monte-mail-queue-throttle.php includes/class-monte-mail-queue-installer.php includes/class-monte-mail-queue-plugin.php includes/class-monte-mail-queue-settings.php
git commit --no-gpg-sign -m "feat: add plugin bootstrap and installer"
```

### Task 2: Queue Persistence And Source Detection

**Files:**
- Create: `includes/class-monte-mail-queue-repository.php`
- Create: `includes/class-monte-mail-queue-source-detector.php`
- Modify: `includes/class-monte-mail-queue-plugin.php`
- Modify: `monte-mail-queue-throttle.php`

- [ ] **Step 1: Create source detector**

Create `Monte_Mail_Queue_Source_Detector::detect()` that scans `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)` for `/wp-content/plugins/{slug}/` and returns the first slug that is not this plugin's own slug.

- [ ] **Step 2: Create repository**

Create methods:

- `enqueue(array $mail, string $source_plugin = ''): int`
- `claim_batch(int $limit): array`
- `mark_sent(int $id): bool`
- `mark_retry(int $id, string $error, int $delay_seconds): bool`
- `mark_failed(int $id, string $error): bool`
- `log(int $queue_id, string $event_type, string $message, string $source_plugin = ''): void`
- `counts(): array`
- `queue_items(string $status = '', int $limit = 100): array`
- `logs(int $limit = 100): array`
- `purge_old_logs(): int`
- `purge_old_queue_items(): int`

Store `to`, `headers`, and `attachments` as JSON. Store `subject` and `message` as text fields. Queue rows include `next_attempt_at` for retry backoff and indexes for `status,next_attempt_at,id` and `status,updated_at`.

- [ ] **Step 3: Include and instantiate classes**

Update bootstrap and coordinator so repository and source detector are available to later tasks.

- [ ] **Step 4: Verify PHP syntax**

Run:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: every file reports `No syntax errors detected`.

- [ ] **Step 5: Commit**

Run:

```bash
git add monte-mail-queue-throttle.php includes/class-monte-mail-queue-plugin.php includes/class-monte-mail-queue-repository.php includes/class-monte-mail-queue-source-detector.php
git commit --no-gpg-sign -m "feat: add queue repository and source detection"
```

### Task 3: Mail Interceptor And Cron Worker

**Files:**
- Create: `includes/class-monte-mail-queue-interceptor.php`
- Create: `includes/class-monte-mail-queue-worker.php`
- Modify: `includes/class-monte-mail-queue-plugin.php`
- Modify: `monte-mail-queue-throttle.php`

- [ ] **Step 1: Create interceptor**

Create a `pre_wp_mail` callback that:

- returns `null` when bypass mode is active
- normalizes `$atts`
- detects source plugin
- checks queue mode and allowed plugin slugs
- enqueues the mail
- logs the queue event
- returns `true`

- [ ] **Step 2: Create worker**

Create cron callback for `wmqt_process_queue` that:

- computes `$limit = max(1, rate_per_minute * 2)`
- claims eligible queued items into `processing`
- stops before the PHP execution deadline by claiming one item at a time
- replays each item with interceptor bypass enabled
- marks sent when `wp_mail()` returns true
- records retry with exponential backoff or final failure when `wp_mail()` returns false or throws
- logs missing attachment paths before replay
- prunes old logs and completed queue rows according to separate retention settings

- [ ] **Step 3: Wire hooks**

Register the interceptor on `pre_wp_mail` and worker on `wmqt_process_queue` from the plugin coordinator.

- [ ] **Step 4: Verify PHP syntax**

Run:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: every file reports `No syntax errors detected`.

- [ ] **Step 5: Commit**

Run:

```bash
git add monte-mail-queue-throttle.php includes/class-monte-mail-queue-plugin.php includes/class-monte-mail-queue-interceptor.php includes/class-monte-mail-queue-worker.php
git commit --no-gpg-sign -m "feat: queue and process wp_mail messages"
```

### Task 4: Admin Views

**Files:**
- Create: `includes/class-monte-mail-queue-admin.php`
- Create: `assets/admin.css`
- Modify: `includes/class-monte-mail-queue-plugin.php`
- Modify: `monte-mail-queue-throttle.php`

- [ ] **Step 1: Create admin menu**

Create top-level menu `Mail Queue` with pages:

- Dashboard
- Settings
- Queue
- Logs

Require `manage_options`.

- [ ] **Step 2: Create Dashboard view**

Render cards for queued, processing, sent, failed, configured rate, per-run limit, and next scheduled cron timestamp.

- [ ] **Step 3: Create Settings view**

Render and save:

- mails per minute
- max retries
- queue mode
- allowed plugin slugs
- log retention days
- completed queue retention days

Use nonce verification and sanitization.

- [ ] **Step 4: Create Queue view**

Render a filterable queue table with ID, recipients, subject, source plugin, status, attempts, last error, queued timestamp, and sent timestamp.

- [ ] **Step 5: Create Logs view**

Render latest log entries with timestamp, event type, queue item ID, source plugin, related message details, and message.

- [ ] **Step 6: Verify PHP syntax**

Run:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: every file reports `No syntax errors detected`.

- [ ] **Step 7: Commit**

Run:

```bash
git add monte-mail-queue-throttle.php includes/class-monte-mail-queue-plugin.php includes/class-monte-mail-queue-admin.php assets/admin.css
git commit --no-gpg-sign -m "feat: add mail queue admin views"
```

### Task 5: Documentation, Packaging, And Verification

**Files:**
- Create: `README.md`
- Create: `monte-mail-queue-throttle.zip`

- [ ] **Step 1: Write README**

Document installation, default behavior, GoDaddy/WP-Cron assumptions, FluentSMTP integration, source plugin filtering, and uninstall/deactivation behavior.

- [ ] **Step 2: Run syntax verification**

Run:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: every file reports `No syntax errors detected`.

- [ ] **Step 3: Package plugin**

Run:

```bash
mkdir -p build/monte-mail-queue-throttle
rsync -a --exclude='.git' --exclude='build' --exclude='docs' ./ build/monte-mail-queue-throttle/
cd build && zip -r ../monte-mail-queue-throttle.zip monte-mail-queue-throttle
```

Expected: `monte-mail-queue-throttle.zip` exists and contains the plugin root folder.

- [ ] **Step 4: Inspect ZIP contents**

Run:

```bash
unzip -l monte-mail-queue-throttle.zip
```

Expected: includes `monte-mail-queue-throttle/monte-mail-queue-throttle.php`, `includes/`, `assets/`, and `README.md`.

- [ ] **Step 5: Commit**

Run:

```bash
git add README.md monte-mail-queue-throttle.zip
git commit --no-gpg-sign -m "docs: add plugin packaging artifact"
```

## Self-Review

- Spec coverage: covered interception, 120-second cron, configurable rate, logs, settings, queue, dashboard, source-plugin filtering, activation/deactivation, and packaging.
- Placeholder scan: no placeholder tasks remain.
- Type consistency: class names use `Monte_Mail_Queue_*`; cron hook is consistently `wmqt_process_queue`; schedule name is consistently `wmqt_two_minutes`; table prefix is consistently `wmqt`.
