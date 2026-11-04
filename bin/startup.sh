#!/bin/sh
# Optional first-run setup for hosts without a terminal. Runs before Apache starts.
#   AUTO_MIGRATE=1          -> php bin/migrate.php (creates/updates the tables; safe to repeat, skips finished steps)
#   BABI_ADMIN_PASSWORD set -> php bin/create-admin.php (creates the FIRST admin only; refuses if one already exists)
#                              also needs BABI_ADMIN_USERNAME and BABI_ADMIN_NAME
# A failure here is printed but never stops the website from starting.
cd /var/www/html || exit 0

if [ "${AUTO_MIGRATE:-0}" = "1" ]; then
    echo "[startup] running database migrations"
    php bin/migrate.php || echo "[startup] migrate.php FAILED (see message above)"
fi

if [ -n "${BABI_ADMIN_PASSWORD:-}" ]; then
    echo "[startup] creating first administrator (skipped if one exists)"
    php bin/create-admin.php || echo "[startup] create-admin.php did not create a user (see message above)"
fi

exit 0
