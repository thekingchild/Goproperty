#!/usr/bin/env bash
# Install drill (P00-06, INSTALLER.md § 12): installs a built release zip the
# way an owner without root would, and proves the result.
#
#   1. unpack the split layout (goproperty-app/ + public_html/)
#   2. php artisan goproperty:install --no-interaction under shared-host
#      limits: max_execution_time=30, memory_limit=256M, exec/proc_open and
#      pcntl disabled
#   3. a second install is refused; cron runs once; check-installation passes
#   4. the site serves pages from public_html; /install is 404
#   5. the installed copy still passes goproperty:verify-package
#   6. its schema equals a plain `php artisan migrate` of the same release
#
# Usage: scripts/release/install-drill.sh <goproperty-x.y.z.zip>
# Env:   DB_CONNECTION (mysql|mariadb), DB_HOST, DB_PORT, DB_USERNAME,
#        DB_PASSWORD: an account that may create and drop databases.
#        PHP=php
set -euo pipefail

if [ $# -ne 1 ]; then
    echo "Usage: $0 <goproperty-x.y.z.zip>" >&2
    exit 64
fi

zip=$(realpath "$1")
php=${PHP:-php}
: "${DB_CONNECTION:?set DB_CONNECTION}" "${DB_HOST:?set DB_HOST}" "${DB_PORT:?set DB_PORT}" "${DB_USERNAME:?set DB_USERNAME}"
DB_PASSWORD=${DB_PASSWORD:-}
export DB_HOST DB_PORT DB_USERNAME DB_PASSWORD

work=$(mktemp -d)
suffix=$(date +%s)$RANDOM
install_db="gp_drill_install_$suffix"
reference_db="gp_drill_reference_$suffix"
port=$((18800 + RANDOM % 1000))
url="http://127.0.0.1:$port"
server_pid=""

# Shared-host limits (INSTALLER.md § 1, § 8).
limits=(-d max_execution_time=30 -d memory_limit=256M
    -d disable_functions=exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,pcntl_fork,pcntl_alarm,pcntl_signal,pcntl_async_signals)

step() { printf '\n==> %s\n' "$*"; }
fail() { echo "INSTALL DRILL FAILED: $*" >&2; exit 1; }

# Runs SQL on the server without a mysql client.
sql() {
    "$php" -r '$pdo = new PDO(sprintf("mysql:host=%s;port=%s", getenv("DB_HOST"), getenv("DB_PORT")), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->exec($argv[1]);' "$1"
}

cleanup() {
    [ -z "$server_pid" ] || kill "$server_pid" 2>/dev/null || true
    sql "drop database if exists \`$install_db\`" || true
    sql "drop database if exists \`$reference_db\`" || true
    rm -rf "$work"
}
trap cleanup EXIT

# Requests a page and checks its status.
expect_status() {
    local path=$1 want=$2 code
    code=$(curl -s -o "$work/body" -w '%{http_code}' "$url$path")
    if [ "$code" != "$want" ]; then
        head -c 2000 "$work/body"
        tail -40 "$app/storage/logs/laravel.log" 2>/dev/null || true
        fail "GET $path returned $code, expected $want"
    fi
    echo "GET $path: $code"
}

step "Unpack the split layout"
mkdir -p "$work/unzipped" "$work/home"
unzip -q "$zip" -d "$work/unzipped"
mv "$work/unzipped/split/goproperty-app" "$work/unzipped/split/public_html" "$work/home/"
app="$work/home/goproperty-app"

sql "create database \`$install_db\` character set utf8mb4 collate utf8mb4_unicode_ci"
sql "create database \`$reference_db\` character set utf8mb4 collate utf8mb4_unicode_ci"

(umask 077
    printf '%s' "$DB_PASSWORD" > "$work/db-password"
    printf 'Drill-admin-%s-passphrase' "$suffix" > "$work/admin-password")

step "Install from the command line under shared-host limits"
(cd "$app" && "$php" "${limits[@]}" artisan goproperty:install --no-interaction \
    --env=production --url="$url" \
    --db-driver="$DB_CONNECTION" --db-host="$DB_HOST" --db-port="$DB_PORT" \
    --db-name="$install_db" --db-user="$DB_USERNAME" --db-password-file="$work/db-password" \
    --admin-name="Drill Admin" --admin-email="drill@example.com" --admin-password-file="$work/admin-password") \
    | tee "$work/install.log" || fail "goproperty:install failed"
grep -q "GoProperty is installed." "$work/install.log" || fail "the install did not finish"
admin_path=$(grep -E '^ADMIN_PATH=' "$app/.env" | cut -d= -f2)
[[ $admin_path =~ ^gp-control-[a-z0-9]{6}$ ]] || fail "ADMIN_PATH is '$admin_path'"
[ -f "$app/storage/app/installed.lock" ] || fail "no installed.lock"
[ -f "$app/bootstrap/cache/config.php" ] || fail "config was not cached"

step "A second install is refused"
if (cd "$app" && "$php" "${limits[@]}" artisan goproperty:install --no-interaction --env=production > "$work/again.log" 2>&1); then
    fail "a second install ran"
fi
grep -q "already installed" "$work/again.log" || { cat "$work/again.log"; fail "the second install gave the wrong reason"; }
echo "refused: already installed"

step "Cron runs once; the installation checks out"
(cd "$app" && "$php" "${limits[@]}" artisan schedule:run --no-ansi > /dev/null)
(cd "$app" && "$php" "${limits[@]}" artisan goproperty:check-installation) || fail "check-installation failed"

step "The site serves pages from public_html"
"$php" "${limits[@]}" -S "127.0.0.1:$port" -t "$work/home/public_html" > "$work/server.log" 2>&1 &
server_pid=$!
for _ in $(seq 1 50); do
    curl -s -o /dev/null "$url/up" && break
    sleep 0.2
done
expect_status / 200
expect_status /login 200
expect_status /up 200
expect_status /install 404
kill "$server_pid" && wait "$server_pid" 2>/dev/null || true
server_pid=""
if grep -qE 'PHP (Fatal|Warning)' "$work/server.log"; then
    cat "$work/server.log"
    fail "PHP warnings while serving"
fi

step "The installed copy still verifies"
(cd "$app" && "$php" artisan goproperty:verify-package) || fail "verify-package failed after the install"

step "The schema equals a plain migrate"
mkdir "$work/reference"
unzip -q "$zip" 'preferred/*' -d "$work/reference"
reference="$work/reference/preferred/goproperty"
printf 'APP_KEY=\nAPP_ENV=production\n' > "$reference/.env"
"$php" "$reference/artisan" key:generate --force --no-interaction > /dev/null
# Environment variables win over .env (the reference config is not cached).
DB_CONNECTION="$DB_CONNECTION" DB_DATABASE="$reference_db" "$php" "$reference/artisan" migrate --force --no-interaction > /dev/null
DB_CONNECTION="$DB_CONNECTION" DB_DATABASE="$reference_db" "$php" "$reference/artisan" goproperty:schema-snapshot > "$work/reference.json"
(cd "$app" && "$php" artisan goproperty:schema-snapshot) > "$work/installed.json"
grep -q '"platform_staff"' "$work/installed.json" || fail "the snapshot holds no tables"
diff -u "$work/reference.json" "$work/installed.json" || fail "the installed schema differs from a plain migrate"
echo "identical: $(grep -c '"name"' "$work/installed.json") columns and tables compared"

step "Install drill passed"
