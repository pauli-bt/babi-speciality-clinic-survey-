# Babi Dental Analytics

Customer acquisition, interaction and marketing analytics for **Babi Speciality Dental Clinic**.
PHP 8.2+, PDO, MySQL 8 / MariaDB 10.5+, Bootstrap 5 and Chart.js (both bundled locally, no internet needed to run), Apache or XAMPP.

Staff record every customer contact (reception visits, phone calls, social-media direct messages), manage appointments, services and campaigns, and the administrator and marketing team see analytics and aggregate reports.

## Contents
1. [Requirements](#1-requirements)
2. [Install on Windows with XAMPP](#2-install-on-windows-with-xampp)
3. [Install on Linux / another Apache server](#3-install-on-linux--another-apache-server)
4. [Verify the installation](#4-verify-the-installation)
5. [Troubleshooting](#5-troubleshooting)
6. [Production and security](#6-production-and-security)
7. [Backup and restore](#7-backup-and-restore)
8. [Using the system](#8-using-the-system)
9. [Tests](#9-tests)
10. [Project layout and documentation](#10-project-layout-and-documentation)

---

## 1. Requirements
| Item | Requirement |
|---|---|
| PHP | 8.2 or newer (developed and tested on 8.3; 8.2 not run here) |
| PHP extensions | `pdo_mysql` and `mbstring` (required); `ctype`, `json`, `openssl` are normally built in. `curl` is needed **only** to run the automated tests |
| Database | MySQL 8 or MariaDB 10.5+ (tested on MariaDB 10.11). Needs `utf8mb4`, InnoDB and trigger support |
| Web server | Apache 2.4 with `mod_rewrite` and `AllowOverride All` for the `public/` folder (XAMPP provides these). PHP's built-in server works for a quick local try-out |
| Folders that must be writable by the web server | `storage/sessions` and `storage/logs` (or the folder named in `SESSION_PATH`) |
| Browser | Any current browser. JavaScript is used for charts, pickers and forms |

The application needs **no** Composer packages, no internet access and no paid service.

## 2. Install on Windows with XAMPP
These steps were written from the project's code and checked on Apache 2.4 + MariaDB on Linux. XAMPP file locations below are XAMPP's usual defaults; if yours differ, adjust the paths.

### Step 1. Put the project in place
Unzip `babi-dental-analytics.zip` so you get `C:\xampp\htdocs\babi-dental-analytics\` (the folder that contains `app`, `public`, `bin`, `README.md`).

### Step 2. Start Apache and MySQL
Open the **XAMPP Control Panel** and start **Apache** and **MySQL**.

### Step 3. Check PHP has the needed extensions
Open *Command Prompt* and run:
```
cd C:\xampp\htdocs\babi-dental-analytics
C:\xampp\php\php.exe -v
C:\xampp\php\php.exe -m
```
`-v` must show PHP 8.2 or higher. The list from `-m` must contain `pdo_mysql` and `mbstring`. If one is missing, open `C:\xampp\php\php.ini`, remove the `;` in front of `extension=pdo_mysql` (or `extension=mbstring`), save, and restart Apache.

### Step 4. Create the database and the two database accounts
Open http://localhost/phpmyadmin, click **SQL**, paste the block below, **change both passwords**, and press **Go**:
```sql
CREATE DATABASE babi_dental CHARACTER SET utf8mb4;
CREATE USER 'babi_app'@'localhost' IDENTIFIED BY 'PUT-A-STRONG-PASSWORD-HERE';
CREATE USER 'babi_migrate'@'localhost' IDENTIFIED BY 'PUT-A-DIFFERENT-STRONG-PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON babi_dental.* TO 'babi_app'@'localhost';
GRANT ALL PRIVILEGES ON babi_dental.* TO 'babi_migrate'@'localhost';
FLUSH PRIVILEGES;
```
* `babi_app` is what the website uses. It can only read and write data, so a bug or attack cannot alter the database structure.
* `babi_migrate` is used only while installing or upgrading the database.
* XAMPP's MySQL `root` account has no password by default. That is fine on a private PC, never on a server.

### Step 5. Create your `.env` file
1. In `C:\xampp\htdocs\babi-dental-analytics\` copy `.env.example` to a new file named `.env` (File Explorer: copy, paste, rename; make sure it is not called `.env.txt`).
2. Open `.env` in Notepad and set (one `KEY=value` per line, no spaces around `=`):
```
APP_ENV=local
APP_URL=http://localhost:8080
APP_TIMEZONE=Africa/Addis_Ababa
TRUST_PROXY=0
DB_HOST=localhost
DB_PORT=3306
DB_NAME=babi_dental
DB_USER=babi_app
DB_PASS=the-babi_app-password-from-step-4
DB_MIGRATE_USER=babi_migrate
DB_MIGRATE_PASS=the-babi_migrate-password-from-step-4
```
* `APP_ENV=local` is for XAMPP on `http://`. On a real server use `production` (section 6).
* If a password contains `#` or spaces, wrap it in double quotes: `DB_PASS="my pass#word"`.
* `APP_TIMEZONE` must stay a valid PHP time zone name; `Africa/Addis_Ababa` is the clinic default (UTC+03:00, no daylight saving).

### Step 6. Point Apache at the `public` folder (important)
The website **must** be served from the `public` folder. Opening `http://localhost/babi-dental-analytics/public/` will **not** work (every link is root-relative and the page answers 404). Use a small virtual host on its own port:

1. Open `C:\xampp\apache\conf\extra\httpd-vhosts.conf` and add at the end:
```apache
Listen 8080
<VirtualHost *:8080>
    ServerName localhost
    DocumentRoot "C:/xampp/htdocs/babi-dental-analytics/public"
    <Directory "C:/xampp/htdocs/babi-dental-analytics/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```
2. Make sure `C:\xampp\apache\conf\httpd.conf` has the line `Include conf/extra/httpd-vhosts.conf` (no `#` in front) and `LoadModule rewrite_module modules/mod_rewrite.so` (no `#`).
3. In the XAMPP Control Panel **Stop** and **Start** Apache. If port 8080 is busy, choose another port and use the same number in `APP_URL`.

Quick alternative without Apache configuration (testing only): in Command Prompt run
`C:\xampp\php\php.exe -S 127.0.0.1:8080 -t public` inside the project folder and set `APP_URL=http://127.0.0.1:8080`.

### Step 7. Create the database tables (migrations)
```
cd C:\xampp\htdocs\babi-dental-analytics
C:\xampp\php\php.exe bin\migrate.php
```
Expected output (a fresh database):
```
baseline: loading /schema.sql (22 statements)
baseline: loading /triggers.sql (2 statements)
baseline: loading /seeders/reference_data.sql (10 statements)
migrating: 002_phase2_auth (3 statements)
migrating: 003_phase3_customers (4 statements)
migrating: 004_phase4_interactions (1 statements)
migrating: 005_phase6_management (2 statements)
database is up to date
```
* `php bin/migrate.php` is safe to repeat: finished migrations are skipped (second run prints only `database is up to date`). It never deletes data.
* The order is fixed: the Phase 1 baseline (`database/schema.sql`, `database/triggers.sql`, `database/seeders/reference_data.sql`), then `database/migrations/002...` upward, each exactly once.
* If a migration stops half-way, fix the cause and restore the pre-upgrade backup (section 7) before retrying; the SQL files are not transactional.

### Step 8. Create the first administrator
```
C:\xampp\php\php.exe bin\create-admin.php
```
It asks for a username, full name and password, and creates the first **Administrator** account. It refuses to run again once an active administrator exists (other accounts are created in the web interface under *Users*).
* On Windows the password you type is **visible** on screen (hidden input only works on Linux/macOS). Do it in private, or set it from variables instead:
```
set BABI_ADMIN_USERNAME=clinic.owner
set BABI_ADMIN_NAME=Clinic Owner
set BABI_ADMIN_PASSWORD=Your-Long-Passphrase-4829
C:\xampp\php\php.exe bin\create-admin.php
```
(PowerShell: `$env:BABI_ADMIN_PASSWORD='...'`). Close the window afterwards.
* Password rules (enforced everywhere): at least 12 characters, at most 72 bytes, at least one letter and one digit, must not contain the username, and must not contain `password`, `qwerty`, `letmein`, `welcome`, `babi`, `admin`, `123456` or `abcdef`.
* There are **no default accounts or passwords**. Nobody can sign in until you do this step.

### Step 9. Open the application
Browse to **http://localhost:8080** (the `APP_URL` you chose) and sign in with the administrator account. Create staff accounts under **Users** (one per person; the role decides what they can do).

## 3. Install on Linux / another Apache server
Same sequence: create database and accounts (Step 4 SQL, using the `mysql` client), create `.env` (Step 5), set the virtual host `DocumentRoot` to the project's `public/` directory with `AllowOverride All` and `Require all granted`, enable `mod_rewrite`, make `storage/` writable by the web user (for example `chown -R www-data storage`), then `php bin/migrate.php` and `php bin/create-admin.php`. A complete production checklist is in [`docs/DEPLOYMENT_CHECKLIST.md`](docs/DEPLOYMENT_CHECKLIST.md). Nginx is not covered by these instructions: every request that is not a real file under `public/` must be passed to `public/index.php`.

## 4. Verify the installation
Run these and tick them off:
1. `php bin/migrate.php` prints `database is up to date`.
2. `php bin/check-timezone.php` prints `OK` lines for the time zone, the MySQL session time zone `+03:00`, a clock skew of about 0s, and ends with `DONE  problems=0`. It only reads the database.
3. The browser shows the sign-in page with the BABI DENTAL logo and styling (not a plain unstyled page).
4. Signing in with the administrator opens the administrator dashboard; **Users**, **Customers**, **Appointments**, **Campaigns**, **Services**, **Analytics** and **Reports** open without errors.
5. Signing out and opening a protected URL sends you to the sign-in page.
6. Reports → *Summary* → CSV downloads a `.csv` file that opens in Excel with correct characters.
7. Typing a wrong password five times locks that username for 15 minutes (this is expected protection).
8. These addresses must **not** work (404 or 403): `/.env`, `/app/bootstrap.php`, `/database/schema.sql`, `/storage/logs/app.log`.

## 5. Troubleshooting
| What you see | Likely cause and fix |
|---|---|
| `Server misconfigured: APP_URL must be an https:// URL in production.` | `.env` is missing or `APP_ENV` is not `local`. On XAMPP use `APP_ENV=local` and an `http://` APP_URL. (Any `APP_ENV` other than `local`/`testing` is treated as production on purpose.) |
| `Server configuration error.` | `APP_TIMEZONE` is not a valid PHP time zone name. Use `Africa/Addis_Ababa`. Details are in `storage/logs/php-error.log`. |
| `Server configuration error: the session folder is not writable.` | Make `storage/sessions` (or your `SESSION_PATH`) exist and be writable by the Apache user. |
| `could not find driver` when running a `bin` script | `pdo_mysql` is not enabled in `php.ini` (Step 3). Always run the `bin` scripts with `C:\xampp\php\php.exe` so they use the same PHP and `php.ini` as the web server. |
| `Access denied for user ...` | Wrong `DB_USER`/`DB_PASS` (or `DB_MIGRATE_*`) in `.env`, or the grants in Step 4 were not run. Passwords with `#` or spaces need double quotes. |
| `Unknown database 'babi_dental'` | Step 4 was not run, or `DB_NAME` differs. |
| Sign-in page looks unstyled or logo missing | The virtual host `DocumentRoot` is not the `public` folder (Step 6). |
| 404 on every page at `/babi-dental-analytics/public/...` | The sub-folder URL is not supported. Use the virtual host/port from Step 6. |
| Redirect to an `https://...example...` address | `APP_ENV=production` with the template `APP_URL`. Set `APP_ENV=local` and the correct `APP_URL` on XAMPP. |
| "Too many attempts" at sign-in (HTTP 429) | A username is locked for 15 minutes after 5 failures (an address after 20). Wait, or an administrator with database access can run `DELETE FROM login_attempts;`. |
| The only administrator forgot the password | Another administrator can issue a reset link (Users → Edit → *Issue password reset link*). If there is none, see "Lost administrator password" in `docs/DEPLOYMENT_CHECKLIST.md` (a verified SQL recovery). |
| Migration error about `SUPER` privilege / binary logging when creating triggers | Only seen on servers with binary logging enabled; set `log_bin_trust_function_creators=1` or ask your DBA. **Not reproduced here** (the tested server has binary logging off). |
| Anything else | Read `storage/logs/php-error.log` and `storage/logs/app.log`, and Apache's `error.log` (`C:\xampp\apache\logs\error.log`). Messages shown in the browser are deliberately short. |

## 6. Production and security
Short version (full checklist: [`docs/DEPLOYMENT_CHECKLIST.md`](docs/DEPLOYMENT_CHECKLIST.md)):
* **`APP_ENV=production`** and a real **`APP_URL=https://your-domain`**. In production the app requires HTTPS, sends HSTS and Secure `__Host-` cookies, and refuses to start if `APP_URL` is not `https://`. There is no application secret/key to generate: sessions are server-side and CSRF tokens are random per session. The secrets are the two database passwords.
* **Browser error display is already off**: the application forces `display_errors=0` and writes problems to `storage/logs/php-error.log`. Also set `display_errors=Off` in `php.ini`.
* **Point the web server's `DocumentRoot` at `public/`.** Everything else (`.env`, `app/`, `storage/`, `database/`, `tests/`, `bin/`, `docs/`) then cannot be requested at all. The project-root `.htaccess` is only a safety net (it returns 403 for anything outside `public/` if the whole folder is served by mistake); it was tested on Apache 2.4.
* **`TRUST_PROXY`**: leave `0`. Set `1` only if the site sits behind a reverse proxy/load balancer that you control, that sets `X-Forwarded-Proto` and appends the real client address to `X-Forwarded-For`, and that cannot be bypassed (the web server is not reachable directly from the internet). When `1`, the rightmost `X-Forwarded-For` address is used for login rate-limiting and the audit trail. Wrongly enabled, a visitor could fake his address.
* **Database least privilege**: the website's `DB_USER` must have only `SELECT, INSERT, UPDATE, DELETE` (Step 4). Remove `DB_MIGRATE_USER`/`DB_MIGRATE_PASS` from the live `.env` after migrations, or keep that file readable only by the account that runs migrations.
* **Protect `.env`, logs, backups and sessions**: keep `.env` outside the web root where possible and readable only by the web-server user; keep `storage/` and backups out of the web root; never e-mail them; set `SESSION_PATH` to a folder outside the project on shared servers.
* **Credential rotation** after a suspected compromise: see section "Rotate credentials" in `docs/DEPLOYMENT_CHECKLIST.md` (database passwords with `ALTER USER`, force sign-out of every user with one SQL statement, reset administrator passwords, review the audit log).

## 7. Backup and restore
Back up the **database** (all clinic data lives there) and your `.env`. Take a backup before every upgrade.
```
mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4 -u root -p babi_dental > babi_backup_2026-10-16.sql
```
XAMPP: `C:\xampp\mysql\bin\mysqldump.exe` (add `-u root -p`). Restore into an **empty** database:
```
mysql -u root -p -e "CREATE DATABASE babi_dental_restored CHARACTER SET utf8mb4"
mysql -u root -p babi_dental_restored < babi_backup_2026-10-16.sql
```
A backup/restore round trip (data, 2 triggers, migration history) was tested on MariaDB 10.11. Store backups encrypted and off the server; they contain personal data. Test a restore from time to time. More in `docs/DEPLOYMENT_CHECKLIST.md`.

## 8. Using the system
Roles, the three interaction channels, original source vs returning customers, appointment statuses, analytics definitions, reports and exports, time zone assumptions, how to deactivate staff/services/campaigns safely, and how to review the audit log are all explained in [`docs/OPERATIONS_GUIDE.md`](docs/OPERATIONS_GUIDE.md).

| Role | In one line |
|---|---|
| Administrator | Everything: users, customers (incl. audited source correction), services, campaigns, appointments, analytics, reports, audit log |
| Receptionist | Records in-person visits (was a service used?), registers/searches customers, manages appointments |
| Call Operator | Records phone calls and appointment-booking outcomes, registers/searches customers, manages appointments |
| Social Media DM Tracker | Records DM conversations and booking outcomes, registers/searches customers, manages appointments |
| Marketing Team | Aggregate analytics, campaign list and aggregate report exports only; **no** customer names, phones, codes or notes |

## 9. Tests
`bash tests/run.sh` (Linux/macOS/WSL or Git Bash; not plain Windows Command Prompt) creates throw-away databases named `babi_test` and `babi_migtest` and test accounts, starts PHP test servers, runs every suite (`tests/run.php`, `tests/phase3.php` through `tests/phase10.php`) and stops the servers. It needs `php` with `curl`, `pdo_mysql`, `mbstring`, and a local root-capable `mysql`/`mariadb` client. **Never run it against a database that holds real data.** `php bin/check-timezone.php` is the read-only time zone diagnostic. Results of the last run are in `docs/PHASE10_FINAL_AUDIT.md`.

## 10. Project layout and documentation
```
app/          PHP code (Controllers, Services, Models, Helpers, Middleware, Validation)
bin/          migrate.php, create-admin.php, check-timezone.php (command line only)
config/       routes.php
database/     schema.sql, triggers.sql, seeders/, migrations/
docs/         guides, per-phase documentation, screenshots
public/       the only web-visible folder: index.php, .htaccess, assets/ (CSS, JS, images, Bootstrap, Chart.js)
resources/    views (the Babi Dental user interface)
storage/      logs/ and sessions/ (runtime, never delivered with real content)
tests/        automated test suites and runner
```
| Document | Subject |
|---|---|
| `docs/OPERATIONS_GUIDE.md` | Day-to-day rules and definitions for staff and administrators |
| `docs/DEPLOYMENT_CHECKLIST.md` | Deployment, smoke test, rollback/backup, security checklists, credential rotation |
| `docs/PHASE10_FINAL_AUDIT.md` | Final audit, exact test results, remaining risks, files changed |
| `docs/PHASE1_ARCHITECTURE.md` ... `docs/PHASE9_SECURITY_REVIEW.md` | Design and verification notes for each phase (there is no separate Phase 2 file) |
| `docs/UI_CUSTOMIZATION_MERGE.md` | How the customised Babi Dental UI was merged. The UI is frozen: `tests/ui-manifest.sha256` lists its checksums |
