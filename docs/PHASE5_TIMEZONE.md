# Phase 5 - Timezone handling

- `APP_TIMEZONE` (default `Africa/Addis_Ababa`, UTC+03:00, no DST) is the canonical application timezone. Only named IANA zones are accepted; an invalid value stops startup with `Invalid APP_TIMEZONE ...` (CLI exit 1, web HTTP 500 with the detail in the error log).
- `App\Helpers\Clock` validates the zone, gives `Clock::now()` and the MySQL offset string (`+03:00`, DST-aware at connection time).
- `Db::pdo()` runs `SET time_zone = '<offset>'` on every connection, so `NOW()`, `CURRENT_TIMESTAMP` and `TIMESTAMPDIFF` use the same clinic-local clock as PHP. DATETIME columns hold clinic-local wall-clock time.
- Interaction date validation uses `Clock::now()`. The 24-hour edit window is elapsed time (`TIMESTAMPDIFF(MINUTE, created_at, NOW())`) and is not affected by local midnight.
- `php bin/check-timezone.php` is read-only: configuration, MySQL session offset, PHP/MySQL clock skew, and `created_at` values in the future (a sign of rows written under another timezone). It never rewrites data; historical inconsistencies need manual investigation with a backup.
- Long-running connections keep the offset chosen at connect time; for DST zones, restart workers across a DST change.

## Tests
`bash tests/run.sh` now also runs `tests/phase5.php`. Each timezone runs in its own child process (`tests/p5_child.php`), fixtures are removed in `finally`, and the shared database is used sequentially, so configurations cannot contaminate each other.

## Not in scope here
Appointment status-history and appointment-closing rules around midnight belong to the appointment management work (Phase 6 per the README) and do not exist in the Phase 4 code, so they are not tested.
