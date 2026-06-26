# Task 2 Report: Add Settings And Configurable Worker Cadence

## Scope completed

Implemented Task 2 exactly in the requested files:

- `includes/class-monte-mail-queue-settings.php`
- `includes/class-monte-mail-queue-installer.php`
- `includes/class-monte-mail-queue-admin.php`
- `includes/class-monte-mail-queue-plugin.php`
- `tests/SettingsTest.php`

## TDD evidence

### RED

Command:

```bash
php tests/run.php
```

Observed failures before production changes:

- `FAIL settings expose new throttle and azure defaults: hour default: expected 1500, got NULL`
- `FAIL settings sanitize worker cadence and azure fields: hour minimum: expected 1, got NULL`
- `FAIL installer cron schedule uses configured worker interval: schedule seconds: expected 420, got 120`
- `FAIL installer reschedule_event clears existing hook and schedules with configured cadence: Call to undefined method Monte_Mail_Queue_Installer::reschedule_event()`
- `FAIL admin save reschedules only when worker interval changes: rescheduled once: expected 1, got 0`
- `FAIL admin settings page renders new cadence and azure fields: hourly field rendered`
- `FAIL plugin admin uses shared installer instance: Property Monte_Mail_Queue_Admin::$installer does not exist`

This confirmed the task requirements were not yet implemented.

### GREEN

Command:

```bash
php tests/run.php
```

Result:

- `PASS test harness records and runs assertions`
- `PASS settings expose new throttle and azure defaults`
- `PASS settings sanitize worker cadence and azure fields`
- `PASS installer cron schedule uses configured worker interval`
- `PASS installer reschedule_event clears existing hook and schedules with configured cadence`
- `PASS admin save reschedules only when worker interval changes`
- `PASS admin settings page renders new cadence and azure fields`
- `PASS plugin admin uses shared installer instance`

## Verification

Commands run:

```bash
php tests/run.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Results:

- All tests passed.
- All PHP files reported `No syntax errors detected`.

## Implementation summary

### Settings

Added new defaults and sanitization for:

- `rate_per_hour`
- `worker_interval_minutes`
- `azure_email_enabled`
- `azure_connection_string`
- `azure_sender_domains`
- `azure_sender_username`
- `azure_default_domain`
- `azure_reply_to`

Added helper sanitizers for Azure connection strings, sender domains, sender usernames, and domains.

### Installer cadence

- Cron schedule interval now reads `worker_interval_minutes`
- Cron display text now uses `Every %d minute(s)`
- Added `Monte_Mail_Queue_Installer::reschedule_event()`
- Initial schedule time now uses the configured interval

### Admin save behavior

- `Monte_Mail_Queue_Admin` now receives the installer dependency
- Settings save now persists the new throttle and Azure fields
- Settings save compares previous and current `worker_interval_minutes`
- Interval changes trigger `reschedule_event()`

### Settings UI

Added UI fields for:

- `rate_per_hour`
- `worker_interval_minutes`
- `azure_email_enabled`
- `azure_connection_string`
- `azure_sender_domains`
- `azure_sender_username`
- `azure_default_domain`
- `azure_reply_to`

Added missing field render helpers for checkbox, textarea, and email inputs.

### Plugin wiring

- `Monte_Mail_Queue_Plugin::admin()` now passes the shared installer into the admin controller

## Self-review

Checked the final diff for scope and risk:

- Changes stayed inside the five Task 2 files named in the brief.
- No unrelated refactors were introduced.
- New production code stayed PHP 7.0-compatible.
- The new tests cover settings defaults, sanitization, installer cadence, admin rescheduling, UI field presence, and plugin wiring.

## Commit

Planned commit message from the brief:

```bash
feat: configure hourly throttle and worker cadence
```

## Fix follow-up for review findings

Addressed the Task 2 review findings without implementing the deferred hourly send-window logic from Task 3/5.

### Changes made

- Worker batch size now uses `rate_per_minute * worker_interval_minutes`, with `worker_interval_minutes` clamped to `1..60`.
- Worker now requests the remaining per-run capacity from `claim_batch()`, instead of hardcoding `1`.
- Dashboard `Per-run limit` now uses the configured worker interval.
- Dashboard now shows the configured worker interval as its own card.
- Settings UI renders `worker_interval_minutes` with `max="60"`.
- Added focused tests for worker batch sizing and dashboard/settings rendering.

### Tests run

Command:

```bash
php tests/run.php
```

Output:

```text
PASS test harness records and runs assertions
PASS settings expose new throttle and azure defaults
PASS settings sanitize worker cadence and azure fields
PASS installer cron schedule uses configured worker interval
PASS installer reschedule_event clears existing hook and schedules with configured cadence
PASS admin save reschedules only when worker interval changes
PASS admin settings page renders new cadence and azure fields
PASS admin dashboard shows cadence-based per-run limit and worker interval
PASS plugin admin uses shared installer instance
PASS worker claims batches using configured worker interval
```

Command:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Output:

```text
No syntax errors detected in ./monte-mail-queue-throttle.php
No syntax errors detected in ./tests/SettingsTest.php
No syntax errors detected in ./tests/WorkerCadenceTest.php
No syntax errors detected in ./tests/bootstrap.php
No syntax errors detected in ./tests/run.php
No syntax errors detected in ./tests/HarnessTest.php
No syntax errors detected in ./includes/class-monte-mail-queue-admin.php
No syntax errors detected in ./includes/class-monte-mail-queue-plugin.php
No syntax errors detected in ./includes/class-monte-mail-queue-settings.php
No syntax errors detected in ./includes/class-monte-mail-queue-interceptor.php
No syntax errors detected in ./includes/class-monte-mail-queue-source-detector.php
No syntax errors detected in ./includes/class-monte-mail-queue-repository.php
No syntax errors detected in ./includes/class-monte-mail-queue-worker.php
No syntax errors detected in ./includes/class-monte-mail-queue-installer.php
No syntax errors detected in ./uninstall.php
```

## Task 2 Re-review Fix

Addressed the re-review regression without broadening Task 2 into Task 3/5 send-window logic.

### Fix notes

- Kept the per-run worker limit as `rate_per_minute * worker_interval_minutes`.
- Kept `worker_interval_minutes` clamped to `1..60`.
- Restored one-at-a-time claiming inside the worker loop with `claim_batch( 1 )`, so the existing queue processing contract stays one item per iteration.
- Added a focused regression test proving that two claimable rows are both sent during a single run when `rate_per_minute = 1` and `worker_interval_minutes = 2`, with no extra rows stranded in `processing`.

### TDD re-review evidence

RED command:

```bash
php tests/run.php
```

RED output:

```text
PASS test harness records and runs assertions
PASS settings expose new throttle and azure defaults
PASS settings sanitize worker cadence and azure fields
PASS installer cron schedule uses configured worker interval
PASS installer reschedule_event clears existing hook and schedules with configured cadence
PASS admin save reschedules only when worker interval changes
PASS admin settings page renders new cadence and azure fields
PASS admin dashboard shows cadence-based per-run limit and worker interval
PASS plugin admin uses shared installer instance
FAIL worker processes each claimed item without stranding processing rows: worker marks each claimed item sent: expected array (
  0 => 101,
  1 => 102,
), got array (
  0 => 101,
)
```

GREEN command:

```bash
php tests/run.php
```

GREEN output:

```text
PASS test harness records and runs assertions
PASS settings expose new throttle and azure defaults
PASS settings sanitize worker cadence and azure fields
PASS installer cron schedule uses configured worker interval
PASS installer reschedule_event clears existing hook and schedules with configured cadence
PASS admin save reschedules only when worker interval changes
PASS admin settings page renders new cadence and azure fields
PASS admin dashboard shows cadence-based per-run limit and worker interval
PASS plugin admin uses shared installer instance
PASS worker processes each claimed item without stranding processing rows
```

### Final verification

Command:

```bash
php tests/run.php
```

Output:

```text
PASS test harness records and runs assertions
PASS settings expose new throttle and azure defaults
PASS settings sanitize worker cadence and azure fields
PASS installer cron schedule uses configured worker interval
PASS installer reschedule_event clears existing hook and schedules with configured cadence
PASS admin save reschedules only when worker interval changes
PASS admin settings page renders new cadence and azure fields
PASS admin dashboard shows cadence-based per-run limit and worker interval
PASS plugin admin uses shared installer instance
PASS worker processes each claimed item without stranding processing rows
```

Command:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Output:

```text
No syntax errors detected in ./monte-mail-queue-throttle.php
No syntax errors detected in ./tests/SettingsTest.php
No syntax errors detected in ./tests/WorkerCadenceTest.php
No syntax errors detected in ./tests/bootstrap.php
No syntax errors detected in ./tests/run.php
No syntax errors detected in ./tests/HarnessTest.php
No syntax errors detected in ./includes/class-monte-mail-queue-admin.php
No syntax errors detected in ./includes/class-monte-mail-queue-plugin.php
No syntax errors detected in ./includes/class-monte-mail-queue-settings.php
No syntax errors detected in ./includes/class-monte-mail-queue-interceptor.php
No syntax errors detected in ./includes/class-monte-mail-queue-source-detector.php
No syntax errors detected in ./includes/class-monte-mail-queue-repository.php
No syntax errors detected in ./includes/class-monte-mail-queue-worker.php
No syntax errors detected in ./includes/class-monte-mail-queue-installer.php
No syntax errors detected in ./uninstall.php
```
