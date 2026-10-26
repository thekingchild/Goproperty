#!/usr/bin/env bash
# Deploy drill (P00-13, docs/runbooks/shared-hosting.md § 2–3): the staging
# path on one machine, under shared-host limits, without SSH:
#
#   1. first-install.sh: goproperty:install into releases/<1> with shared/.env
#      and shared/storage; current -> releases/<1>; cron line uses current/
#   2. remote-deploy.sh: a second release goes live; .env stays shared
#   3. a release whose migration fails never goes live
#   4. BACKUP_FIRST=1 with no backup destination stops the deploy
#   5. KEEP_RELEASES prunes old releases
#
# Usage: scripts/deploy/deploy-drill.sh <release.tar.gz from build-release.sh>
# Env:   DB_CONNECTION, DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD (may create
#        and drop databases); PHP=php
set -euo pipefail

if [ $# -ne 1 ]; then
    echo "Usage: $0 <release.tar.gz>" >&2
    exit 64
fi

archive=$(realpath "$1")
php=${PHP:-php}
: "${DB_CONNECTION:?set DB_CONNECTION}" "${DB_HOST:?set DB_HOST}" "${DB_PORT:?set DB_PORT}" "${DB_USERNAME:?set DB_USERNAME}"
DB_PASSWORD=${DB_PASSWORD:-}
export DB_HOST DB_PORT DB_USERNAME DB_PASSWORD

root=$(cd "$(dirname "$0")/../.." && pwd)
work=$(mktemp -d)
app="$work/goproperty"
suffix=$(date +%s)$RANDOM
database="gp_deploy_drill_$suffix"
port=$((19800 + RANDOM % 1000))
url="http://127.0.0.1:$port"
server_pid=""

step() { printf '\n==> %s\n' "$*"; }
fail() { echo "DEPLOY DRILL FAILED: $*" >&2; exit 1; }

sql() {
    "$php" -r '$pdo = new PDO(sprintf("mysql:host=%s;port=%s", getenv("DB_HOST"), getenv("DB_PORT")), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->exec($argv[1]);' "$1"
}

stop_server() {
    [ -z "$server_pid" ] || { kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true; }
    server_pid=""
}

cleanup() {
    stop_server
    sql "drop database if exists \`$database\`" || true
    rm -rf "$work"
}
trap cleanup EXIT

# Shared-host limits for every PHP run (INSTALLER.md § 1, § 8).
cat > "$work/php" <<WRAPPER
#!/usr/bin/env bash
exec "$php" -d max_execution_time=30 -d memory_limit=256M \\
    -d disable_functions=exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,pcntl_fork,pcntl_alarm,pcntl_signal,pcntl_async_signals "\$@"
WRAPPER
chmod +x "$work/php"

copy() { cp "$archive" "$work/upload-$1.tar.gz"; echo "$work/upload-$1.tar.gz"; }

expect_status() {
    local path=$1 want=$2 code
    code=$(curl -s -o "$work/body" -w '%{http_code}' "$url$path")
    [ "$code" = "$want" ] || { head -c 1500 "$work/body"; fail "GET $path returned $code, expected $want"; }
    echo "GET $path: $code"
}

serve() {
    stop_server
    # The document root is current/public, as the runbook sets it.
    "$php" -S "127.0.0.1:$port" -t "$app/current/public" > "$work/server.log" 2>&1 &
    server_pid=$!
    for _ in $(seq 1 50); do curl -s -o /dev/null "$url/up" && break; sleep 0.2; done
}

live() { readlink "$app/current"; }

sql "create database \`$database\` character set utf8mb4 collate utf8mb4_unicode_ci"
(umask 077
    printf '%s' "$DB_PASSWORD" > "$work/db-password"
    printf 'Drill-admin-%s-passphrase' "$suffix" > "$work/admin-password")

step "First install into the deploy layout"
"$root/scripts/deploy/first-install.sh" "$app" "$(copy one)" 20261027000001-one "$work/php" -- \
    --no-interaction --env=production --url="$url" \
    --db-driver="$DB_CONNECTION" --db-host="$DB_HOST" --db-port="$DB_PORT" \
    --db-name="$database" --db-user="$DB_USERNAME" --db-password-file="$work/db-password" \
    --admin-name="Drill Admin" --admin-email="drill@example.com" --admin-password-file="$work/admin-password" \
    | tee "$work/install.log"
[ "$(live)" = releases/20261027000001-one ] || fail "current is $(live)"
[ -L "$app/releases/20261027000001-one/.env" ] || fail "the release's .env is not a link"
grep -qE '^APP_KEY="?base64:' "$app/shared/.env" || fail "shared/.env has no APP_KEY"
grep -qE '^ADMIN_PATH="?gp-control-[a-z0-9]{6}"?$' "$app/shared/.env" || fail "shared/.env has no ADMIN_PATH"
[ "$(stat -c %a "$app/shared/.env")" = 600 ] || fail "shared/.env is not mode 600"
[ -f "$app/shared/storage/app/installed.lock" ] || fail "installed.lock is not in shared storage"
grep -q "$app/current/artisan schedule:run" "$work/install.log" || fail "the cron line does not use current/artisan"
admin_path=$(grep -E '^ADMIN_PATH=' "$app/shared/.env" | cut -d= -f2 | tr -d '"')
env_hash=$(sha256sum "$app/shared/.env" | cut -d' ' -f1)
serve
expect_status / 200
expect_status /login 200
expect_status "/$admin_path/login" 200

step "Deploy a second release"
"$root/scripts/deploy/remote-deploy.sh" "$app" "$(copy two)" 20261027000002-two "$work/php"
[ "$(live)" = releases/20261027000002-two ] || fail "current is $(live)"
[ -L "$app/releases/20261027000002-two/.env" ] || fail "the new release's .env is not a link"
[ "$(sha256sum "$app/shared/.env" | cut -d' ' -f1)" = "$env_hash" ] || fail "the deploy changed shared/.env"
serve
expect_status / 200
expect_status /up 200
expect_status "/$admin_path/login" 200

step "A release whose migration fails never goes live"
mkdir "$work/broken"
tar -xzf "$archive" -C "$work/broken"
cat > "$work/broken/database/migrations/2999_01_01_000000_deploy_drill_fails.php" <<'MIGRATION'
<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        throw new RuntimeException('Deploy drill: this migration fails on purpose.');
    }
};
MIGRATION
tar -czf "$work/upload-broken.tar.gz" -C "$work/broken" .
if "$root/scripts/deploy/remote-deploy.sh" "$app" "$work/upload-broken.tar.gz" 20261027000003-broken "$work/php" > "$work/broken.log" 2>&1; then
    fail "the broken release deployed"
fi
grep -q "fails on purpose" "$work/broken.log" || { cat "$work/broken.log"; fail "the broken release failed for another reason"; }
[ "$(live)" = releases/20261027000002-two ] || fail "current moved to $(live)"
serve
expect_status / 200
echo "refused; current still -> releases/20261027000002-two"

step "BACKUP_FIRST=1 without a backup destination stops the deploy"
if BACKUP_FIRST=1 "$root/scripts/deploy/remote-deploy.sh" "$app" "$(copy four)" 20261027000004-four "$work/php" > "$work/backup.log" 2>&1; then
    fail "deployed without a backup"
fi
grep -q "Backing up the database" "$work/backup.log" || { cat "$work/backup.log"; fail "no backup was attempted"; }
[ "$(live)" = releases/20261027000002-two ] || fail "current moved to $(live)"
echo "stopped; current still -> releases/20261027000002-two"

step "Old releases are pruned"
KEEP_RELEASES=2 "$root/scripts/deploy/remote-deploy.sh" "$app" "$(copy five)" 20261027000005-five "$work/php" > /dev/null
[ "$(live)" = releases/20261027000005-five ] || fail "current is $(live)"
kept=$(find "$app/releases" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort | tr '\n' ' ')
[ "$kept" = "20261027000004-four 20261027000005-five " ] || fail "releases kept: $kept"
echo "kept: $kept"
serve
expect_status / 200

if grep -qE 'PHP (Fatal|Warning)' "$work/server.log"; then
    cat "$work/server.log"
    fail "PHP warnings while serving"
fi

step "Deploy drill passed"
