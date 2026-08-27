#!/bin/sh

set -eu

APP_ROOT=${WALLOS_APP_ROOT:-/var/www/html}
PHP_BIN=${WALLOS_PHP_BIN:-/usr/local/bin/php}
PHP_CONFIG_FILE=${WALLOS_PHP_CONFIG_FILE:-/usr/local/etc/php/conf.d/zz-upload-limits.ini}
RUN_DIR=${WALLOS_RUN_DIR:-/run/wallos}
READY_FILE=${WALLOS_READY_FILE:-$RUN_DIR/ready}
STARTUP_LOG=${WALLOS_STARTUP_LOG:-/var/log/startup.log}
CRON_LOG_DIR=${WALLOS_CRON_LOG_DIR:-/var/log/cron}
DATABASE_FILE=$APP_ROOT/db/wallos.db
RESTORE_TRANSACTION_FILE=$APP_ROOT/db/.wallos-restore-transaction
DATABASE_MAINTENANCE_FILE=${WALLOS_DATABASE_MAINTENANCE_FILE:-$APP_ROOT/.tmp/database-maintenance.lock}
DB_ENDPOINT_DIR=$APP_ROOT/endpoints/db
PUID=${PUID:-82}
PGID=${PGID:-82}
SHUTDOWN_TIMEOUT=${WALLOS_SHUTDOWN_TIMEOUT:-10}

PHP_FPM_PID=
NGINX_PID=
CROND_PID=
shutdown_in_progress=0

case "$SHUTDOWN_TIMEOUT" in
  ''|*[!0-9]*)
    echo "WALLOS_SHUTDOWN_TIMEOUT must be an integer between 1 and 60." >&2
    exit 1
    ;;
esac
if [ "$SHUTDOWN_TIMEOUT" -lt 1 ] || [ "$SHUTDOWN_TIMEOUT" -gt 60 ]; then
  echo "WALLOS_SHUTDOWN_TIMEOUT must be an integer between 1 and 60." >&2
  exit 1
fi

case "$PUID:$PGID" in
  *[!0-9:]*|:*|*:)
    echo "PUID and PGID must be positive numeric IDs." >&2
    exit 1
    ;;
esac
NGINX_UID=$(id -u nginx)
NGINX_GID=$(id -g nginx)
if [ "$PUID" -eq 0 ] || [ "$PGID" -eq 0 ] \
  || [ "$PUID" -eq "$NGINX_UID" ] || [ "$PGID" -eq "$NGINX_GID" ]; then
  echo "PUID and PGID must not use root or the reserved Nginx worker IDs." >&2
  exit 1
fi

mkdir -p "$RUN_DIR"
rm -f "$READY_FILE"
echo "Startup preflight is running..." > "$STARTUP_LOG"

cat <<'EOF' > "$PHP_CONFIG_FILE"
memory_limit=512M
upload_max_filesize=64M
post_max_size=256M
max_file_uploads=50
max_input_time=120
max_execution_time=120
EOF

groupmod -o -g "$PGID" www-data
usermod -o -u "$PUID" www-data
# Alpine's nginx package adds the web worker to the PHP data group. Remove that
# supplementary membership before either service starts so a compromised web
# worker cannot read SQLite, WAL, or backup contents.
delgroup nginx www-data 2>/dev/null || true

# Never interpret an empty database file as a new installation. For an
# existing database, all checks before the migration runner are read-only.
if [ -e "$RESTORE_TRANSACTION_FILE" ] || [ -L "$RESTORE_TRANSACTION_FILE" ]; then
  echo "Refusing to start: an incomplete database restore transaction requires recovery." >&2
  exit 1
fi
if [ -e "$DATABASE_MAINTENANCE_FILE" ] || [ -L "$DATABASE_MAINTENANCE_FILE" ]; then
  echo "Refusing to start: a stale database maintenance marker requires recovery." >&2
  exit 1
fi

if [ -e "$DATABASE_FILE" ]; then
  if [ ! -f "$DATABASE_FILE" ] || [ ! -s "$DATABASE_FILE" ]; then
    echo "Refusing to start: wallos.db exists but is not a non-empty regular file." >&2
    exit 1
  fi
  "$PHP_BIN" "$DB_ENDPOINT_DIR/verify.php" --pre-migration
else
  if [ "${WALLOS_REQUIRE_EXISTING_DB:-0}" = "1" ]; then
    echo "Refusing to start: WALLOS_REQUIRE_EXISTING_DB=1 but wallos.db is missing." >&2
    exit 1
  fi
  mkdir -p "$APP_ROOT/db"
  "$PHP_BIN" "$APP_ROOT/endpoints/cronjobs/createdatabase.php"
fi

# Database setup remains offline: no web or cron process can observe a
# partially migrated schema.
"$PHP_BIN" "$APP_ROOT/endpoints/db/migrate.php"
"$PHP_BIN" "$APP_ROOT/endpoints/db/verify.php"

mkdir -p "$APP_ROOT/images/uploads/logos/avatars"
mkdir -p "$APP_ROOT/backups"
mkdir -p "$APP_ROOT/.tmp"
mkdir -p "$CRON_LOG_DIR"

# Database and backup mounts contain private data. Preserve the configured
# PUID/PGID sharing model while keeping the Nginx worker out of that group.
chown -R www-data:www-data "$APP_ROOT/db" "$APP_ROOT/backups"
find "$APP_ROOT/db" "$APP_ROOT/backups" -type d -exec chmod 0770 {} +
find "$APP_ROOT/db" "$APP_ROOT/backups" -type f -exec chmod 0660 {} +
# Nginx may traverse (but not list or modify) only the DB mount root so it can
# stat the fixed restore journal. The reserved-ID check and group removal above
# keep database files inaccessible to the worker.
chmod 0771 "$APP_ROOT/db"
chown www-data:www-data "$APP_ROOT/.tmp"
chmod 0711 "$APP_ROOT/.tmp"

# Uploaded logos are public assets served by Nginx, so they remain readable
# but never executable. Do not recursively alter the shared /tmp directory.
chown -R www-data:www-data "$APP_ROOT/images/uploads/logos"
find "$APP_ROOT/images/uploads/logos" -type d -exec chmod 0755 {} +
find "$APP_ROOT/images/uploads/logos" -type f -exec chmod 0644 {} +
chmod 0750 "$CRON_LOG_DIR"
chown root:root "$RUN_DIR"
chmod 0755 "$RUN_DIR"

process_is_running() {
  process_pid=${1:-}
  [ -n "$process_pid" ] && kill -0 "$process_pid" 2>/dev/null
}

signal_if_running() {
  process_pid=${1:-}
  process_signal=$2
  if process_is_running "$process_pid"; then
    kill -"$process_signal" "$process_pid" 2>/dev/null || true
  fi
}

reap_processes() {
  set +e
  [ -n "$PHP_FPM_PID" ] && wait "$PHP_FPM_PID" 2>/dev/null
  [ -n "$CROND_PID" ] && wait "$CROND_PID" 2>/dev/null
  [ -n "$NGINX_PID" ] && wait "$NGINX_PID" 2>/dev/null
  set -e
}

shutdown_once() {
  [ "$shutdown_in_progress" -eq 1 ] && return 0
  shutdown_in_progress=1
  trap '' TERM INT QUIT
  rm -f "$READY_FILE"

  signal_if_running "$NGINX_PID" QUIT
  signal_if_running "$PHP_FPM_PID" QUIT
  signal_if_running "$CROND_PID" TERM

  elapsed=0
  while [ "$elapsed" -lt "$SHUTDOWN_TIMEOUT" ]; do
    if ! process_is_running "$PHP_FPM_PID" \
      && ! process_is_running "$CROND_PID" \
      && ! process_is_running "$NGINX_PID"; then
      break
    fi
    sleep 1
    elapsed=$((elapsed + 1))
  done

  signal_if_running "$NGINX_PID" KILL
  signal_if_running "$PHP_FPM_PID" KILL
  signal_if_running "$CROND_PID" KILL
  reap_processes
}

handle_signal() {
  signal_name=$1
  echo "Received $signal_name; stopping Wallos services."
  shutdown_once
  exit 0
}

trap 'handle_signal TERM' TERM
trap 'handle_signal INT' INT
trap 'handle_signal QUIT' QUIT

echo "Launching php-fpm"
php-fpm -F &
PHP_FPM_PID=$!

if [ "${WALLOS_CRON_ENABLED:-1}" = "1" ]; then
  echo "Launching crond"
  crond -f &
  CROND_PID=$!
else
  echo "Cron disabled by WALLOS_CRON_ENABLED"
fi

echo "Launching nginx"
nginx -g 'daemon off;' &
NGINX_PID=$!

touch "$READY_FILE"
chown root:root "$READY_FILE"
chmod 0644 "$READY_FILE"

# Supervise every enabled critical service. An unexpected clean exit is still
# a container failure because Wallos is no longer fully operational.
while :; do
  failed_name=
  failed_pid=

  if ! process_is_running "$PHP_FPM_PID"; then
    failed_name=php-fpm
    failed_pid=$PHP_FPM_PID
  elif [ -n "$CROND_PID" ] && ! process_is_running "$CROND_PID"; then
    failed_name=crond
    failed_pid=$CROND_PID
  elif ! process_is_running "$NGINX_PID"; then
    failed_name=nginx
    failed_pid=$NGINX_PID
  fi

  if [ -n "$failed_name" ]; then
    set +e
    wait "$failed_pid"
    failed_status=$?
    set -e
    [ "$failed_status" -eq 0 ] && failed_status=1
    echo "Critical process $failed_name exited unexpectedly (status $failed_status)." >&2
    shutdown_once
    exit "$failed_status"
  fi

  sleep 1
done
