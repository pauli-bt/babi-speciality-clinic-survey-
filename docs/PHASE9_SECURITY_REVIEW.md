# Phase 9 - Security review and hardening

Baseline: `babi-dental-analytics-phase8-ui-merged.zip` (the user's customised UI merged with Phase 8). **No UI file was changed**; see "UI files" below. No database migration is required.

## Scope reviewed
Routes and the router, authentication and sessions, authorization (every route x every role), controllers and services, SQL and transactions, forms and CSRF, output escaping, Phase 8 reports and CSV, security headers, configuration and secret handling, public/exposed files, file uploads, dependencies and the test suite.

Method: static review of all 4.4k lines of PHP, then an executable probe suite (`tests/phase9.php`) that attacks the running application. The authorization test derives its matrix from `config/routes.php` and the `role_permissions` table, so any future route added without protection fails the suite.

## Confirmed findings and fixes
| # | Severity | Finding | Fix |
|---|---|---|---|
| 1 | Medium | **Client address behind a reverse proxy.** `Http::ip()` always used `REMOTE_ADDR`. Behind a proxy every user shares one address, so the per-IP login limit (20 failures / 15 min) let one attacker lock out the login page for everyone, the limit did nothing against any single attacker, and the audit trail recorded the proxy. | With `TRUST_PROXY=1` the rightmost `X-Forwarded-For` entry (appended by our own proxy) is used if it is a valid IP; leftmost entries are ignored (spoofable). Without `TRUST_PROXY` the header is ignored. `app/Helpers/Http.php` |
| 2 | Medium (deployment) | **PHP errors were never silenced.** XAMPP's default `php.ini` has `display_errors=On`. The application emits "Array to string conversion" warnings when array-typed input is sent (found at 20 places); with display on, these reveal file paths. | `Http::hardenRuntime()` (called from `public/index.php`) forces `display_errors=0`, logs to `storage/logs/php-error.log`. Verified with a server started with `display_errors=1` plus a negative control. |
| 3 | Medium (deployment) | **Project root not protected on Apache.** If the whole project folder is served from `htdocs`, `.env` (DB credentials), `app/`, `storage/`, `database/`, `tests/` and `README.md` are downloadable. | Project-root `.htaccess` refuses everything outside `public/` (and `.env`, `.git`). `DocumentRoot = public/` remains the recommended setup. **Not verified on a real Apache** (see manual checks). |
| 4 | Low | **Reset page fed the lockout counter.** `GET /reset-password` with no token was recorded as a failed attempt; 20 plain requests locked the login page for that IP. | Only requests that actually supply a bad token are counted. `AuthController::showReset` |
| 5 | Low | **`/interactions?channel[]=x` returned HTTP 500** (TypeError, details logged only). | `is_string` check. `InteractionController::index` |

## Verified as already sound (no change)
Password hashing (bcrypt cost 12, rehash, timing equalisation for unknown users); session id regeneration at login, strict mode, HttpOnly + SameSite=Strict, `__Host-` Secure cookie in production, idle and absolute timeouts, re-validation of the account and `session_version` on every request (deactivation, role change and password change end live sessions); per-username and per-IP lockout with a generic message; CSRF on every POST including login and logout (GET logout refused); admin-issued single-use reset tokens stored as SHA-256 with expiry; last-administrator protections; prepared statements everywhere (no user input reaches SQL text; LIMIT/OFFSET are integers; the audit filter escapes LIKE wildcards); output escaping on every view (stored and reflected payloads tested on 18 pages and 192 parameter/page combinations); no uploads, no `eval`/shell/`unserialize`, no third-party PHP packages; CSP without `unsafe-inline`/`unsafe-eval`, frame, base and form restrictions; nosniff, no-store, Referrer-Policy, HSTS over HTTPS; reports and exports authorised server-side, aggregate-only, filenames from whitelisted values, CSV formula protection, audit rows without personal data; business rules (channel/customer immutability, 24-hour window, source write-once, reception cannot create appointments, appointment state machine, single history row on duplicate submit) cannot be bypassed by field tampering.

## Tests
`tests/phase9.php` (67 checks, part of `bash tests/run.sh`): authorization matrix for every route and role, privacy of indirect endpoints, sessions and cookies, fixation, deactivation/role/password invalidation, lockout, proxy address handling, reset-page abuse, stored/reflected XSS, SQL injection payloads, array-typed parameters, error display, headers, exposed files, dangerous functions, tampering with business rules, export audit.

Test-expectation changes (not weakening; all caused by the user's deliberate UI customisation, found by running the untouched baseline first):
- `tests/run.php`, `tests/phase3.php`, `tests/phase4.php`: six assertions looked for `babi-logo.jpg` in rendered pages; the customised layouts correctly reference `babi-logo.png`. They now check for the PNG. The "logo file served unmodified" check (JPG byte-for-byte) is unchanged.
- `tests/phase7.php`: the analytics tab pills carry an inline `style=` attribute (in the customised `analytics/_head.php`). The "no inline style" check now ignores exactly that attribute (`style=""` / the two #173b77 colour declarations) and still fails for any other inline script, style or handler.
- `tests/run.sh`: also starts a fourth server (port 8096) with `display_errors=1` and runs Phase 9.

Baseline note: the untouched uploaded project measured **900 passed / 7 failed** (the 7 above); the previously reported 1,107 total is not reproducible by `tests/run.sh` (it counts 907 checks on the baseline).

## Remaining risks and limitations
- The customised `analytics/_head.php` has an inline `style` attribute. The CSP (`style-src 'self'`) makes browsers ignore it, and `app.css` already defines the same colours for the active tab, so no visual difference is expected - **not verified in a browser**. Removing it later is safe.
- "Array to string conversion" warnings for array-typed input still occur at 20 call sites (now only logged; no 5xx, no display). Not fixed individually to avoid speculative edits.
- Appointments are visible/manageable by all three entry roles regardless of channel (they can already search all customers); a design choice from Phase 6, not changed.
- Username lockout is per username: a known username can be locked for 15 minutes by someone who guesses wrongly 5 times (availability trade-off of any lockout).
- Sessions are files under `storage/sessions` (or `SESSION_PATH`); no two-factor authentication; password resets are administrator-issued (no e-mail).
- Static assets served directly by Apache do not get the PHP security headers (nosniff etc.); configure at the web-server level if required.
- Performance and brute-force behaviour were tested on a small dataset only.

## Production recommendations
1. `APP_ENV=production`, HTTPS only, `APP_URL=https://...`; `TRUST_PROXY=1` only behind a proxy that appends the client address to `X-Forwarded-For` and sets `X-Forwarded-Proto`.
2. Virtual host `DocumentRoot` = `public/`; keep `.env` and `SESSION_PATH` outside the web root; `.env` readable only by the web user.
3. Separate runtime DB account (DML only) and migration account; strong unique passwords; backups of the database.
4. Set `display_errors=Off` in `php.ini` as well (the app also forces it), and review `storage/logs/` regularly; restrict its permissions.
5. Rotate the first administrator password after install; review the audit log (`/admin/audit`) for `auth.login_blocked`, `auth.access_denied` and `export.*`.

## Manual verification still required
Windows/XAMPP behaviour; the root `.htaccess` on real Apache (request `/babi-dental-analytics/.env` and `/babi-dental-analytics/app/bootstrap.php`: both must be 403); visual check that pages look exactly as before (no screenshots were compared); real-proxy deployment of the `X-Forwarded-For` handling.

## UI files
**None changed.** `resources/views/**` and `public/assets/**` (CSS, JS, images, vendored Bootstrap and Chart.js) are byte-identical to the uploaded baseline (verified with `diff -r`). Files changed in Phase 9: `app/Helpers/Http.php`, `app/Controllers/AuthController.php`, `app/Controllers/InteractionController.php`, `public/index.php`, new `.htaccess`, `.env.example` (comment), `README.md`, `docs/PHASE9_SECURITY_REVIEW.md`, and the test files listed above.
