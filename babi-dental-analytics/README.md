# Babi Dental Analytics

Customer acquisition & marketing analytics for Babi Speciality Dental Clinic (PHP 8.2+, MySQL 8 / MariaDB 10.5+, Bootstrap 5).

## Status
| Phase | State |
|---|---|
| 1 Architecture & database | Done, tested (`docs/PHASE1_ARCHITECTURE.md`) |
| 2 Authentication, permissions, user management | Done, tested |
| 3 Customer management (register, search, duplicate warning, selection, audited corrections) | **Done, tested, inspected in a browser** (`docs/PHASE3_CUSTOMERS.md`) |
| 4 Three channel questionnaires, appointments, 24-hour edit rule | **Done, tested, inspected in a browser** (`docs/PHASE4_INTERACTIONS.md`) |
| 5-10 Campaign/service/appointment management, analytics, reports, security review, delivery docs | Not started |

## Install (development)
1. Create a database and two MySQL accounts: a runtime account with `SELECT, INSERT, UPDATE, DELETE` and a migration account with DDL rights.
2. `cp .env.example .env` and fill it in (use `APP_ENV=local` for http://localhost).
3. `php bin/migrate.php` (fresh DB gets the baseline; an existing database keeps its data and gets only the pending migrations).
4. `php bin/create-admin.php` (prompts for the first administrator; refuses if one exists).
5. `php -S 127.0.0.1:8080 -t public`, then open http://127.0.0.1:8080. Apache: document root = `public/` (`.htaccess` included). Nginx: send unknown paths to `public/index.php`.

## Tests
`bash tests/run.sh` builds throw-away databases, starts three PHP servers and runs `tests/run.php` (Phase 1-2 regression), `tests/phase3.php` and `tests/phase4.php`. Needs php-curl, php-mbstring, php-mysql and a local root DB socket for creating the test databases.
