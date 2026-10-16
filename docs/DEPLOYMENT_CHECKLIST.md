# Deployment, security and recovery checklists

Written for any host; it does not assume a provider and does not promise that a given host (free or paid) supports this stack. **Verify every host requirement below before committing to it.** Items marked *(verified)* were exercised during the Phase 10 audit on Apache 2.4.58 / PHP 8.3 / MariaDB 10.11 (Linux); everything else is a requirement or recommendation.

## A. Hosting requirements (check before you choose a host)
- [ ] PHP 8.2+ with `pdo_mysql` and `mbstring` (CLI access to run `php bin/migrate.php` and `php bin/create-admin.php`, same PHP version as the web server).
- [ ] MySQL 8 or MariaDB 10.5+; you can create a database and **two** accounts; the migration account may create tables and **triggers** (some shared hosts refuse the `TRIGGER` privilege; the application cannot run without the triggers). Servers with binary logging may also require `log_bin_trust_function_creators=1` *(not reproduced)*.
- [ ] You can set the site's **document root to the `public/` folder** (a dedicated domain or subdomain). The application does **not** run from a sub-folder URL *(verified: 404)*.
- [ ] Apache 2.4 with `mod_rewrite`, `AllowOverride All` for `public/` *(verified)*; `mod_headers` is not required (headers are sent by PHP). No Apache modules beyond a normal PHP setup are needed.
- [ ] A valid HTTPS certificate for the domain (production refuses to start without an `https://` `APP_URL`).
- [ ] Shell or SFTP access; ability to keep `.env` and backups outside the web root; ability to take and store database backups; accurate server clock.

## B. Deployment checklist
1. [ ] Create the database and the two accounts (README step 4), strong **different** passwords (20+ random characters). Runtime account: `SELECT, INSERT, UPDATE, DELETE` only.
2. [ ] Upload/unzip the project **outside** the public web root; set the virtual host `DocumentRoot` to `<project>/public`.
3. [ ] Create `.env` from `.env.example`: `APP_ENV=production`, `APP_URL=https://your-domain` (no trailing slash, not the template value), `APP_TIMEZONE=Africa/Addis_Ababa`, `TRUST_PROXY=0` (see D), real DB settings. *(Wrong or missing values stop the app with a clear message: verified.)*
4. [ ] `chmod 600 .env`, owned by the account that runs PHP; `storage/` writable by the web user only (e.g. `chown -R www-data:www-data storage; chmod -R u+rwX,go-rwx storage`). Prefer `SESSION_PATH` outside the project.
5. [ ] `php bin/migrate.php` → `database is up to date` *(verified; repeat-safe)*. Then remove `DB_MIGRATE_USER`/`DB_MIGRATE_PASS` from `.env` (or keep the file unreadable to the web user).
6. [ ] `php bin/create-admin.php` (Linux hides the password while typing). Record the password in a password manager.
7. [ ] `php bin/check-timezone.php` → `DONE  problems=0` *(verified)*.
8. [ ] PHP `php.ini`: `display_errors=Off`, `log_errors=On`, `expose_php=Off`. (The app also forces `display_errors=0`: verified.)
9. [ ] The `tests/` folder may stay or be removed; it is not web-reachable when the document root is `public/`. **Never run `tests/run.sh` on the live server** (it creates and drops the databases `babi_test` and `babi_migtest` and test accounts).
10. [ ] Enable automatic database backups (section F) and OS/PHP security updates.

## C. Security configuration checklist
- [ ] `APP_ENV=production` (any value other than `local`/`testing` counts as production, so a typo is safe) *(verified)*.
- [ ] HTTPS only; HSTS is sent automatically by the app over HTTPS; redirect plain HTTP to HTTPS (the app also does it when `APP_URL` is https) *(verified)*.
- [ ] No application secret exists to generate: sessions are server-side and CSRF tokens are random per session. The secrets to protect are the two database passwords and the administrator password.
- [ ] Document root is `public/`; these must answer 404/403: `/.env`, `/.env.example`, `/app/bootstrap.php`, `/storage/logs/app.log`, `/database/schema.sql`, `/tests/run.php`, `/README.md` *(verified 404 with docroot=public; 403 with the root `.htaccess` when the whole folder is exposed)*.
- [ ] `.env`, `storage/`, backups and exports are readable only by the necessary accounts; backups are encrypted and stored off the server.
- [ ] Database least privilege (B1). The web account cannot change the schema.
- [ ] `TRUST_PROXY` explained in D.
- [ ] One account per person; remove accounts of leavers (*Deactivate account*); the administrator role is given to as few people as possible.
- [ ] Server firewall: only 80/443 public; MySQL not exposed to the internet.
- [ ] Browser check: no mixed-content warnings; cookie name starts with `__Host-` and is Secure (production over HTTPS: verified by test with a proxy header).

## D. `TRUST_PROXY`: when it is safe
Leave `TRUST_PROXY=0` if visitors connect straight to Apache. Set `1` **only** when all are true: (1) a reverse proxy/load balancer you control terminates HTTPS in front of the app; (2) it sets `X-Forwarded-Proto` and **appends** the real client address to `X-Forwarded-For`; (3) the application server accepts connections only from that proxy. With `1` the app trusts `X-Forwarded-Proto` for HTTPS detection and uses the **rightmost** `X-Forwarded-For` entry as the client address (login rate limiting, audit trail) *(verified)*. With `0` those headers are ignored *(verified)*. Wrongly set to `1`, a visitor could fake HTTPS or his address.

## E. Post-deployment smoke test
Do this on the live URL with the administrator account, then with one test account per role (create temporary accounts, deactivate them afterwards).
1. [ ] `http://your-domain` redirects to `https://your-domain`; certificate valid; sign-in page shows the BABI DENTAL logo and styling.
2. [ ] Wrong password shows one generic message; correct password signs in; sign-out ends the session (back button does not show protected pages).
3. [ ] Administrator: every menu item opens (Users, Customers, Interactions, Appointments, Campaigns, Services, Audit log, Analytics, Reports) *(28 pages returned 200 on the tested install)*.
4. [ ] Receptionist/Call Operator/DM Tracker accounts: register a test customer (ID `BDC-######` appears), record one interaction in their own channel; phone/DM: book a test appointment; confirm Analytics and Reports return **403** for these roles.
5. [ ] Appointments: confirm → completed (after its time) works; an illegal change (e.g. requested → completed) is refused.
6. [ ] Marketing account: sees Analytics, Campaigns, Reports; sees **no** customer names/phones/IDs anywhere; CSV downloads and opens with correct characters.
7. [ ] Audit log shows the test actions (sign-ins, customer, interaction, export) with no names or phone numbers.
8. [ ] `php bin/check-timezone.php` reports no problems; displayed times are Addis Ababa time.
9. [ ] The URLs in C (`/.env`, `/app/bootstrap.php`, …) are not downloadable.
10. [ ] `storage/logs/php-error.log` has no unexpected errors; delete the test customer data or mark it clearly (customers can be archived, not deleted).
11. [ ] Take a first backup and test restoring it into a scratch database (F).

## F. Backup, restore and rollback
**Back up before every upgrade and on a schedule** (daily is sensible; keep several generations).
```
mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4 -u BACKUP_USER -p babi_dental > babi_YYYY-MM-DD.sql
```
Also keep a copy of `.env` (in a password manager, not with the dump). Windows/XAMPP: `C:\xampp\mysql\bin\mysqldump.exe`.
**Restore** into an empty database:
```
mysql -u root -p -e "CREATE DATABASE babi_dental CHARACTER SET utf8mb4"
mysql -u root -p babi_dental < babi_YYYY-MM-DD.sql
```
Then re-create the two accounts and grants (README step 4) if the server is new. *Round trip verified: users, roles, permissions, audit log and migration history match, both triggers present.* Test restores regularly.
**Upgrade procedure:** (1) maintenance window; (2) database backup; (3) keep the old project folder; (4) unzip the new version, copy `.env` over; (5) `php bin/migrate.php`; (6) smoke test (E).
**Rollback:** migrations are forward-only and not transactional, so rollback = (1) put the old project folder back, (2) restore the pre-upgrade database backup into an empty database and point `DB_NAME` at it (or drop and recreate the original), (3) smoke test. Data entered after the backup is lost, so decide quickly and note the time.

## G. Rotate credentials after a suspected compromise
Act in this order; the first two steps stop active attackers.
1. **Sign everyone out now**: `UPDATE users SET session_version = session_version + 1;` *(verified: every open session is rejected immediately)*. Optionally also delete the files in the session folder.
2. **Change the database passwords**: `ALTER USER 'babi_app'@'localhost' IDENTIFIED BY 'new-long-random'; ALTER USER 'babi_migrate'@'localhost' IDENTIFIED BY 'another-long-random';` then update `DB_PASS`/`DB_MIGRATE_PASS` in `.env` *(verified: old password rejected, site works after updating `.env`)*.
3. **Reset every administrator and staff password** (Users → Edit → *Issue password reset link*), or the SQL recovery in H for an administrator. Review who still needs an account; deactivate the rest.
4. **Review the audit log** since before the suspected date: failed/blocked sign-ins, new users, role changes, `customer.source_correct`, exports, access-denied bursts. Note times and addresses.
5. If `.env` or a backup leaked, treat everything in it as compromised (both DB passwords). If the server itself was compromised, rebuild it from a clean image, restore a backup taken **before** the incident, and rotate again.
6. Check web-server and `storage/logs` for the attacker's requests; fix the cause (outdated software, weak password, exposed folder) before returning to service.
7. Decide whether personal data was exposed and whether notification of staff/customers is required under the laws that apply to you (this document is not legal advice).

## H. Lost administrator password
If another administrator exists: Users → Edit → *Issue password reset link*. If the only administrator is locked out, on the server run *(verified)*:
```
php -r "echo password_hash('Temporary-Long-Passphrase-3318', PASSWORD_BCRYPT, ['cost'=>12]);"
```
then in SQL (paste the printed hash):
```sql
UPDATE users SET password_hash='<hash>', must_change_password=1, is_active=1, session_version=session_version+1 WHERE username='clinic.owner';
DELETE FROM login_attempts;
```
Sign in with the temporary passphrase; the system forces a new password at once.

## I. Remaining deployment risks (read before go-live)
See `docs/PHASE10_FINAL_AUDIT.md`: nothing here has been run on Windows/XAMPP or in a real browser by the author of these checks, the application has not been load-tested, and there is no second factor for sign-in.
