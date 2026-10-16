#!/usr/bin/env bash
# Phase 2 test runner. Needs: php 8.2+ (curl, pdo_mysql, mbstring), MariaDB/MySQL, and a local root-capable
# `mariadb`/`mysql` client (socket auth) used ONLY to create throw-away test databases and accounts.
set -u
cd "$(dirname "$0")/.."
CLI=$(command -v mariadb || command -v mysql)
PW='Test-Only-Pw-48213'
if ! $CLI -e 'SELECT 1' >/dev/null 2>&1; then
  (setsid nohup mysqld_safe >/tmp/mysqld.log 2>&1 &)
  for i in $(seq 1 40); do $CLI -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 1; done
fi
$CLI <<SQL
DROP DATABASE IF EXISTS babi_test; DROP DATABASE IF EXISTS babi_migtest;
CREATE DATABASE babi_test CHARACTER SET utf8mb4; CREATE DATABASE babi_migtest CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS 'babi_app'@'localhost' IDENTIFIED BY '$PW';
CREATE USER IF NOT EXISTS 'babi_migrate'@'localhost' IDENTIFIED BY '$PW';
GRANT SELECT,INSERT,UPDATE,DELETE ON babi_test.* TO 'babi_app'@'localhost';
GRANT SELECT,INSERT,UPDATE,DELETE ON babi_migtest.* TO 'babi_app'@'localhost';
GRANT ALL ON babi_test.* TO 'babi_migrate'@'localhost';
GRANT ALL ON babi_migtest.* TO 'babi_migrate'@'localhost';
FLUSH PRIVILEGES;
SQL
# Phase-1 database with sample rows, to prove the migration keeps data and rules
$CLI babi_migtest < database/schema.sql && $CLI babi_migtest < database/triggers.sql && $CLI babi_migtest < database/seeders/reference_data.sql
$CLI babi_migtest <<'SQL'
INSERT INTO users(name,username,password_hash,role_id) VALUES('Legacy Admin','legacy','x',1);
INSERT INTO customers(customer_code,name,original_source_id,first_acquisition_channel,first_acquisition_at,created_by_user_id) VALUES('BDC-000001','Sample A',2,'phone',NOW(),1);
INSERT INTO interactions(customer_id,channel,interaction_reason,is_first_contact,interaction_at,recorded_by_user_id,booking_outcome) VALUES(1,'phone','first_contact',1,NOW(),1,'confirmed');
INSERT INTO interactions(customer_id,channel,interaction_reason,interaction_at,social_platform_id,recorded_by_user_id,booking_outcome) VALUES(1,'social_dm','campaign_response',NOW(),1,1,'requested');
INSERT INTO appointments(customer_id,service_id,status,origin_interaction_id,created_by_user_id) VALUES(1,1,'confirmed',1,1);
SQL
export DB_HOST=localhost DB_USER=babi_app DB_PASS=$PW DB_MIGRATE_USER=babi_migrate DB_MIGRATE_PASS=$PW APP_ENV=testing APP_TIMEZONE=UTC
rm -rf /tmp/babi_sessions_test && mkdir -p /tmp/babi_sessions_test
DB_NAME=babi_test php bin/migrate.php > /tmp/migrate_fresh.log 2>&1
(DB_NAME=babi_migtest php bin/migrate.php > /tmp/migrate_legacy.log 2>&1; DB_NAME=babi_migtest php bin/migrate.php > /tmp/migrate_legacy2.log 2>&1)
SRV=""
start() { # port env...
  local port=$1; shift
  env "$@" PHP_CLI_SERVER_WORKERS=4 SESSION_PATH=/tmp/babi_sessions_test php -S 127.0.0.1:$port -t public >/tmp/srv_$port.log 2>&1 &
  SRV="$SRV $!"
}
start 8099 DB_NAME=babi_test APP_URL=http://127.0.0.1:8099
start 8098 DB_NAME=babi_test APP_ENV=production APP_URL=https://babi.example.test TRUST_PROXY=1
start 8097 DB_NAME=babi_test DB_PASS=wrong-password
# Phase 9: a server whose php.ini shows errors (like XAMPP's default) to prove the app still never prints them
env DB_NAME=babi_test APP_URL=http://127.0.0.1:8096 PHP_CLI_SERVER_WORKERS=4 SESSION_PATH=/tmp/babi_sessions_test php -d display_errors=1 -d html_errors=0 -d error_reporting=-1 -S 127.0.0.1:8096 -t public >/tmp/srv_8096.log 2>&1 &
SRV="$SRV $!"
sleep 1
rm -f storage/logs/app.log
DB_NAME=babi_test PW=$PW php tests/run.php
RC=$?
DB_NAME=babi_test PW=$PW php tests/phase3.php
RC=$((RC + $?))
DB_NAME=babi_test PW=$PW php tests/phase4.php
RC=$((RC + $?))
DB_NAME=babi_test PW=$PW php tests/phase5.php
RC=$((RC + $?))
DB_NAME=babi_test PW=$PW php tests/phase6.php
RC=$((RC + $?))
DB_NAME=babi_test PW=$PW php tests/phase7.php
RC=$((RC + $?))
DB_NAME=babi_test PW=$PW php tests/phase8.php
RC=$((RC + $?))
DB_NAME=babi_test PW=$PW php tests/phase9.php
RC=$((RC + $?))
DB_NAME=babi_test PW=$PW php tests/phase10.php
RC=$((RC + $?))
kill $SRV 2>/dev/null
exit $RC
