# Phase 10 - Final audit, delivery report and remaining risks

Source: the Phase 9 ZIP (`babi-dental-analytics-phase9.zip`). The user's custom UI is frozen: `resources/` and `public/assets/` are byte-identical to Phase 9 (57 files, checked by `tests/ui-manifest.sha256`, which `tests/phase10.php` verifies on every run).

## 1. What the audit covered
Login/logout/sessions/passwords/permissions; customer registration, search, duplicate warning and IDs; the three channels; appointments (creation, status machine, history); services and campaigns; analytics and reports; CSV safety and aggregate-only access; audit logging and time zone; configuration and error handling; migrations; Apache protection; UI assets and navigation. Method: PHP syntax check of every file, the full automated suite, plus hands-on verification on a **real Apache 2.4.58 + PHP 8.3 + MariaDB 10.11** (fresh install following the README, strict smoke test of 28 pages, CSV download, role account creation, backup/restore round trip, credential rotation, forced sign-out, administrator recovery, time zone diagnostic, misconfiguration experiments).

## 2. Defects found and fixed (all within finalization scope)
| # | Severity | Defect | Evidence | Fix |
|---|---|---|---|---|
| 1 | **High** for beginners | **A verbatim copy of `.env.example` broke the configuration.** The loader did not understand `# comments` after a value, so `DB_USER` became `babi_app   # runtime account...` (database login fails) and `APP_ENV` became `production   # production \| local...`, which the code did not recognise as production | Parsed a copy of the old file: `isProduction(): false`, DB_USER contained the comment | `.env.example` rewritten with comments on their own lines (no inline comments); `Env::load` now strips ` # comment` from unquoted values, handles quoted values, a UTF-8 BOM and CRLF (`app/Helpers/Env.php`) |
| 2 | **High** (fail-open) | **A typo in `APP_ENV` silently turned production protections off**: `APP_ENV=prod` served the site over plain HTTP with non-Secure cookies | Experiment E: HTTP 200 over http | `Env::isProduction()` is now true for anything except an explicit `local` or `testing`; a typo gets HTTPS enforcement (`app/Helpers/Env.php`) |
| 3 | Medium | **Unwritable session folder gave a normal-looking login page that could never sign anyone in** (warnings only in the log) | Experiment on Apache: HTTP 200, warnings in log | `Session::start` fails with a clear 500 message (no path shown) and logs the path (`app/Helpers/Session.php`) |

Everything else audited behaved as specified; Phase 9's fixes (proxy-aware client address, `display_errors` off, root `.htaccess`, reset-page counter, array-typed `channel`) were re-verified on real Apache and in the suite.

Not defects but documented: Windows `bin/create-admin.php` shows the typed password (hidden input needs `stty`; environment-variable alternative documented); there is no CLI to reset a sole administrator's password (verified SQL recovery documented); the application cannot run from a sub-folder URL (virtual host/port or document root on `public/` required; documented); `docs/PHASE4_INTERACTIONS.md` still says "reception does not change appointment status ... belongs to Phase 5": since Phase 6, entry roles (including reception) can update an existing appointment's status on the Appointments page, while **creating** appointments from reception remains impossible (tested). Please confirm this is the intended rule.

## 3. Test results
Environment: Linux, PHP 8.3.6, MariaDB 10.11 (throw-away databases `babi_test`, `babi_migtest`), Apache 2.4.58 + mod_php for the hands-on checks. Command: `bash tests/run.sh`, run to completion; **exit code 0**.

| Suite | Passed | Failed | Skipped |
|---|---:|---:|---:|
| Phase 1-2 regression (auth, permissions, headers, migrations fresh/legacy, logo) | 197 | 0 | 0 |
| Phase 3 customers | 164 | 0 | 0 |
| Phase 4 interactions and appointments | 196 | 0 | 0 |
| Phase 5 time zone | 54 | 0 | 0 |
| Phase 6 services, campaigns, appointments | 107 | 0 | 0 |
| Phase 7 analytics | 106 | 0 | 0 |
| Phase 8 reports and CSV | 83 | 0 | 0 |
| Phase 9 security probes (route x role matrix, privacy, sessions, injection, headers) | 67 | 0 | 0 |
| Phase 10 configuration, fail-safe startup, migrations, release hygiene, frozen UI | 49 | 0 | 0 |
| **Total** | **1,023** | **0** | **0** |

Also run and passed (outside the suite): `php -l` on all 109 PHP files (0 syntax errors); `php bin/check-timezone.php` on a freshly installed database (`DONE problems=0`, session zone +03:00, skew 0s); fresh install per the README on Apache; `bin/migrate.php` twice (second run a no-op); backup/restore round trip; credential rotation; forced sign-out; administrator recovery; 28-page smoke test; both Apache layouts (document root `public/`: every sensitive path 404; whole folder under `htdocs`: 403 for `.env`, `app/`, `storage/`, `database/`, `tests/`, `bin/`, `README.md`).

Negative control: with the original `Env.php`, `Session.php` and `.env.example` restored, 12 Phase 10 checks fail; with the fixes all 49 pass, so the new tests detect the defects they describe.

Earlier-phase tests: **none changed in Phase 10**. (In Phase 9 six logo assertions and one inline-style assertion were updated because the user's customised UI legitimately differs from the old expectation; see `docs/PHASE9_SECURITY_REVIEW.md`.)

Not run: Windows/XAMPP; PHP 8.2; MySQL 8 (only MariaDB); any browser; load tests. The previously reported total of 1,107 checks could not be reproduced: the suite contained 907 checks at the Phase 8 baseline and 1,023 now.

## 4. UI preservation
Compared the delivered tree with the Phase 9 source: no file under `resources/` or `public/assets/` changed (including `babi-logo.png`, `babi-logo.jpg`, `app.css`, both layouts, dashboards, analytics and report views). `tests/ui-manifest.sha256` lists SHA-256 checksums of all 57 UI files; after an **intentional** future UI edit refresh it with:
`find resources public/assets -type f | LC_ALL=C sort | xargs sha256sum > tests/ui-manifest.sha256` (run from the project root).
No screenshots were compared and no browser was used; "unchanged" is established by byte comparison, not by looking at the pages.

## 5. Files changed in Phase 10
Application: `app/Helpers/Env.php`, `app/Helpers/Session.php`.
Configuration: `.env.example`.
Tests: `tests/phase10.php` (new), `tests/ui-manifest.sha256` (new), `tests/run.sh` (runs Phase 10).
Documentation: `README.md` (rewritten as the installation guide), `docs/OPERATIONS_GUIDE.md`, `docs/DEPLOYMENT_CHECKLIST.md`, `docs/PHASE10_FINAL_AUDIT.md` (all new).
No UI file, no migration, no schema change, no existing test changed.

## 6. Verified vs not verified
**Verified here (real run):** items listed in section 1 and the test results above, on Linux with Apache 2.4.58, PHP 8.3.6 and MariaDB 10.11.
**Not verified (needs you):**
- Windows and XAMPP: the XAMPP file paths and `httpd-vhosts.conf` edit are written from XAMPP's usual layout and from the Linux Apache test of the same directives; PHP 8.2 itself (tested on 8.3); MySQL 8 (tested on MariaDB); Windows Command Prompt behaviour of `bin` scripts; hosts with binary logging and restricted `TRIGGER` privilege.
- Browsers: visual appearance, charts, print-to-PDF output, opening CSV in Excel, mobile layout.
- A real HTTPS certificate and a real reverse proxy (HTTPS and the proxy headers were exercised with test requests only).
- Load, large data volumes and long-running behaviour (cache, session files growth, audit log growth).
- Any host other than the one used here; backup scheduling; e-mail (the application sends none).

## 7. Remaining risks
1. **No second factor**; sign-in is username + password with lockout (5 failures / username, 20 / address, 15 minutes).
2. **Username lockout can be triggered by anyone who knows a username** (availability trade-off).
3. **Appointments are visible/manageable by all entry roles across channels** (design decision, not changed).
4. **Sessions are files** on the server; no shared store for multiple web servers.
5. **Audit log and `login_attempts` grow without limit**; no automatic purge or archival.
6. **PHP "Array to string conversion" warnings** still occur at 20 places when a client sends array-typed parameters (logged, never shown, no 5xx).
7. **Inline `style` attribute** in `analytics/_head.php` is blocked by the Content-Security-Policy (no visible effect expected, `app.css` styles the active tab); not confirmed in a browser.
8. **Static assets** served directly by Apache do not carry the PHP-added security headers.
9. **Backups are manual**; no scheduling, encryption or off-site copy is provided.
10. **Not "production-ready" merely because tests pass**: complete `docs/DEPLOYMENT_CHECKLIST.md` (hosting checks, HTTPS, least-privilege accounts, smoke test, backup/restore test) on the real server before real clinic data is entered.
