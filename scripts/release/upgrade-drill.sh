#!/usr/bin/env bash
# Upgrade drill (P09-06, INSTALLER.md § 10 and § 12): installs the PREVIOUS
# release zip the way an owner would, adds data, then upgrades it to the NEW
# zip by the folder swap of docs/runbooks/upgrade.md, twice, under shared-host
# limits (max_execution_time=30, memory_limit=256M, exec/proc_open and pcntl
# disabled):
#
# Over SSH (the command line):
#   1. unpack the previous split layout; goproperty:install --no-interaction;
#      cron once; add data; keep a copy of the database (the backup)
#   2. php artisan down; goproperty-app → goproperty-app.previous (and
#      public_html); unpack the new release; copy .env and storage/ across
#   3. migrate --force; db:seed InstallSeeder; optimize; up; cron once
#   4. check-installation passes; the database records the new release; the
#      audit chain verifies; verify-package passes; pages serve
#   5. rollback: the folders swapped back and the database restored from the
#      copy; the previous release passes check-installation with its data
#
# Without SSH (the console's upgrade step over HTTP, with curl):
#   6. the previous split layout installed by walking the web wizard (the
#      owner is handed a console session); add data
#   7. the folder swap as above, with no command run on the server
#   8. Console → Operations → Version shows the new release and what is
#      pending; Run pending migrations: password, authenticator code and the
#      backup tick, then one batch per request (one migration each here) until
#      the install seeders ran; visitors get 503 while it runs, the console
#      does not; the site is open again at the end
#   9. check-installation passes; the database records the new release; the
#      audit log has the upgrade's entries and verifies
#
# Both upgraded databases keep the data added before the upgrade, and both
# schemas equal a plain `php artisan migrate` of the new release.
#
# Usage: scripts/release/upgrade-drill.sh <previous.zip> <new.zip>
# Env:   DB_CONNECTION (mysql|mariadb), DB_HOST, DB_PORT, DB_USERNAME,
#        DB_PASSWORD: an account that may create and drop databases.
#        PHP=php
set -euo pipefail

if [ $# -ne 2 ]; then
    echo "Usage: $0 <previous.zip> <new.zip>" >&2
    exit 64
fi

previous_zip=$(realpath "$1")
new_zip=$(realpath "$2")
php=${PHP:-php}
here=$(cd "$(dirname "$0")" && pwd)
: "${DB_CONNECTION:?set DB_CONNECTION}" "${DB_HOST:?set DB_HOST}" "${DB_PORT:?set DB_PORT}" "${DB_USERNAME:?set DB_USERNAME}"
DB_PASSWORD=${DB_PASSWORD:-}
export DB_HOST DB_PORT DB_USERNAME DB_PASSWORD
# Environment variables win over a site's .env: the installed copies must
# read their own key, environment and database.
unset APP_KEY APP_ENV APP_DEBUG APP_URL DB_DATABASE

work=$(mktemp -d)
suffix=$(date +%s)$RANDOM
cli_db="gp_upgrade_cli_$suffix"
backup_db="gp_upgrade_backup_$suffix"
web_db="gp_upgrade_web_$suffix"
reference_db="gp_upgrade_reference_$suffix"
port=$((21800 + RANDOM % 1000))
url="http://127.0.0.1:$port"
web_port=$((22800 + RANDOM % 1000))
# localhost, not 127.0.0.1: curl sends the console's Secure cookie over
# plain http only to localhost.
web_url="http://localhost:$web_port"
server_pid=""
web_pid=""

limits=(-d max_execution_time=30 -d memory_limit=256M
    -d disable_functions=exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,pcntl_fork,pcntl_alarm,pcntl_signal,pcntl_async_signals)

step() { printf '\n==> %s\n' "$*"; }
fail() { echo "UPGRADE DRILL FAILED: $*" >&2; exit 1; }

# SQL on the server without a mysql client: sql runs statements, sql_value
# prints the first column of the first row.
sql() {
    "$php" -r '$pdo = new PDO(sprintf("mysql:host=%s;port=%s", getenv("DB_HOST"), getenv("DB_PORT")), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->exec($argv[1]);' "$1"
}
sql_value() {
    "$php" -r '$pdo = new PDO(sprintf("mysql:host=%s;port=%s", getenv("DB_HOST"), getenv("DB_PORT")), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); echo $pdo->query($argv[1])->fetchColumn();' "$1"
}

# Copies every table of one database into another, emptied first: the
# drill's stand-in for "back up the database" and "restore the backup".
copy_database() {
    "$php" -r '$pdo = new PDO(sprintf("mysql:host=%s;port=%s", getenv("DB_HOST"), getenv("DB_PORT")), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        [, $from, $to] = $argv;
        $pdo->exec("set foreign_key_checks = 0");
        foreach ($pdo->query("select table_name from information_schema.tables where table_schema = " . $pdo->quote($to) . " and table_type = \"BASE TABLE\"")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec("drop table `$to`.`$table`");
        }
        foreach ($pdo->query("select table_name from information_schema.tables where table_schema = " . $pdo->quote($from) . " and table_type = \"BASE TABLE\"")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec("create table `$to`.`$table` like `$from`.`$table`");
            // Generated columns (e.g. one active lease per unit) are computed again.
            $columns = $pdo->query("select column_name from information_schema.columns where table_schema = " . $pdo->quote($from) . " and table_name = " . $pdo->quote($table) . " and coalesce(generation_expression, \"\") = \"\" order by ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
            $list = implode(", ", array_map(fn ($column) => "`$column`", $columns));
            $pdo->exec("insert into `$to`.`$table` ($list) select $list from `$from`.`$table`");
        }' "$1" "$2"
}

cleanup() {
    [ -z "$server_pid" ] || kill "$server_pid" 2>/dev/null || true
    [ -z "$web_pid" ] || kill "$web_pid" 2>/dev/null || true
    for db in "$cli_db" "$backup_db" "$web_db" "$reference_db"; do
        sql "drop database if exists \`$db\`" || true
    done
    rm -rf "$work"
}
trap cleanup EXIT

# Unpacks a zip's split layout into <home>/goproperty-app and <home>/public_html.
unpack_split() {
    local zip=$1 home=$2 tmp
    tmp=$(mktemp -d "$work/unzip.XXXX")
    unzip -q "$zip" 'split/*' -d "$tmp"
    mkdir -p "$home"
    mv "$tmp/split/goproperty-app" "$tmp/split/public_html" "$home/"
    rm -rf "$tmp"
}

# INSTALLER.md § 10 step 5, as File Manager would do it: the current folders
# become *.previous, the new release goes in their place, and .env and
# storage/ come across. Compiled views are left behind: the new release's
# files are older than them, so Laravel would keep serving the old ones.
swap_in() {
    local zip=$1 home=$2
    mv "$home/goproperty-app" "$home/goproperty-app.previous"
    mv "$home/public_html" "$home/public_html.previous"
    unpack_split "$zip" "$home"
    cp -a "$home/goproperty-app.previous/.env" "$home/goproperty-app/.env"
    rm -rf "$home/goproperty-app/storage"
    cp -a "$home/goproperty-app.previous/storage" "$home/goproperty-app/storage"
    find "$home/goproperty-app/storage/framework/views" -type f -name '*.php' -delete
}

# Data an upgrade must keep: an agency, its owner, a setting.
add_data() {
    local db=$1
    sql "insert into \`$db\`.organizations (name, slug, type, status, created_at, updated_at) values ('Drill Homes', 'drill-homes', 'agency', 'active', '2026-11-01 09:00:00', '2026-11-01 09:00:00');
        insert into \`$db\`.users (name, email, password, created_at, updated_at) values ('Ada Drill', 'ada@drill.example', '\$2y\$12\$abcdefghijklmnopqrstuuW1oZ8YQb3XyI2m3Jx6t0t1q2w3e4r5t', '2026-11-01 09:00:00', '2026-11-01 09:00:00');
        insert into \`$db\`.organization_user (organization_id, user_id, is_default, created_at, updated_at)
            select o.id, u.id, 1, now(), now() from \`$db\`.organizations o, \`$db\`.users u where o.slug = 'drill-homes' and u.email = 'ada@drill.example';
        insert into \`$db\`.settings (scope_type, scope_id, \`key\`, value, created_at, updated_at) values ('global', 0, 'upgrade_drill.marker', '\"kept\"', now(), now())"
}

# Prints the fingerprint of the data to keep (upgrade-fingerprint.php).
fingerprint() { "$php" "$here/upgrade-fingerprint.php" "$@"; }

artisan() {
    local app=$1
    shift
    (cd "$app" && "$php" "${limits[@]}" artisan "$@")
}

serve() {
    local home=$1 address=$2 log=$3
    "$php" "${limits[@]}" -S "$address" -t "$home/public_html" >> "$log" 2>&1 &
    echo $!
}

wait_for() {
    for _ in $(seq 1 50); do
        curl -s -o /dev/null "$1" && return 0
        sleep 0.2
    done
    fail "$1 did not answer"
}

expect_status() {
    local base=$1 path=$2 want=$3 log=$4 code
    code=$(curl -s -o "$work/body" -w '%{http_code}' "$base$path")
    if [ "$code" != "$want" ]; then
        head -c 2000 "$work/body"
        tail -40 "$log" 2>/dev/null || true
        fail "GET $path returned $code, expected $want"
    fi
    echo "GET $path: $code"
}

new_version=$(unzip -p "$new_zip" 'preferred/goproperty/VERSION' | tr -d '[:space:]')
previous_version=$(unzip -p "$previous_zip" 'preferred/goproperty/VERSION' | tr -d '[:space:]')
[ "$new_version" != "$previous_version" ] || fail "both zips are version $new_version"
echo "Upgrading $previous_version → $new_version on $DB_CONNECTION"

for db in "$cli_db" "$backup_db" "$web_db" "$reference_db"; do
    sql "create database \`$db\` character set utf8mb4 collate utf8mb4_unicode_ci"
done
(umask 077
    printf '%s' "$DB_PASSWORD" > "$work/db-password"
    printf 'Drill-admin-%s-passphrase' "$suffix" > "$work/admin-password")

# ---------------------------------------------------------------------------
# The new release's schema from a plain migrate, to compare with
# ---------------------------------------------------------------------------

step "Reference: a plain migrate of the new release"
mkdir "$work/reference"
unzip -q "$new_zip" 'preferred/*' -d "$work/reference"
reference="$work/reference/preferred/goproperty"
printf 'APP_KEY=\nAPP_ENV=production\n' > "$reference/.env"
"$php" "$reference/artisan" key:generate --force --no-interaction > /dev/null
DB_CONNECTION="$DB_CONNECTION" DB_DATABASE="$reference_db" "$php" "$reference/artisan" migrate --force --no-interaction > /dev/null
DB_CONNECTION="$DB_CONNECTION" DB_DATABASE="$reference_db" "$php" "$reference/artisan" goproperty:schema-snapshot > "$work/reference.json"
grep -q '"platform_staff"' "$work/reference.json" || fail "the reference snapshot holds no tables"

# ---------------------------------------------------------------------------
# 1–5. Over SSH: migrate --force, then a rollback
# ---------------------------------------------------------------------------

home="$work/cli-home"
app="$home/goproperty-app"

step "SSH: install the previous release ($previous_version) from the command line"
unpack_split "$previous_zip" "$home"
artisan "$app" goproperty:install --no-interaction \
    --env=production --url="$url" \
    --db-driver="$DB_CONNECTION" --db-host="$DB_HOST" --db-port="$DB_PORT" \
    --db-name="$cli_db" --db-user="$DB_USERNAME" --db-password-file="$work/db-password" \
    --admin-name="Drill Admin" --admin-email="drill@example.com" --admin-password-file="$work/admin-password" \
    > "$work/cli-install.log" || { cat "$work/cli-install.log"; fail "goproperty:install of the previous release failed"; }
artisan "$app" schedule:run --no-ansi > /dev/null
artisan "$app" goproperty:check-installation > "$work/check.log" || { cat "$work/check.log"; fail "the previous release does not pass check-installation"; }
[ "$(sql_value "select version from \`$cli_db\`.installation_state where id = 1")" = "$previous_version" ] || fail "the previous install did not record $previous_version"

step "SSH: add data and back up the database"
add_data "$cli_db"
last_audit=$(sql_value "select coalesce(max(id), 0) from \`$cli_db\`.activity_log")
fingerprint "$cli_db" > "$work/cli-before.json"
grep -q 'drill-homes' "$work/cli-before.json" || fail "the data was not added"
copy_database "$cli_db" "$backup_db"
echo "copied into $backup_db"

step "SSH: maintenance mode, then swap in the new release ($new_version)"
artisan "$app" down --retry=60 > /dev/null
swap_in "$new_zip" "$home"
[ -f "$app/storage/framework/down" ] || fail "maintenance mode did not come across with storage/"
[ "$(cat "$app/VERSION")" = "$new_version" ] || fail "the new folder is not $new_version"

step "SSH: migrate, install seeders, caches, up"
started=$(date +%s)
artisan "$app" migrate --force --no-interaction | tee "$work/cli-migrate.log" || fail "migrate --force failed"
artisan "$app" db:seed --class='Database\Seeders\InstallSeeder' --force --no-interaction > /dev/null || fail "the install seeders failed"
artisan "$app" optimize > /dev/null || fail "php artisan optimize failed"
artisan "$app" up > /dev/null
echo "upgraded in $(( $(date +%s) - started )) s"
[ ! -f "$app/storage/framework/down" ] || fail "the site is still in maintenance mode"

step "SSH: the upgraded installation checks out"
artisan "$app" schedule:run --no-ansi > /dev/null
artisan "$app" goproperty:check-installation || fail "check-installation failed after the upgrade"
artisan "$app" goproperty:audit-verify || fail "the audit log chain is broken after the upgrade"
(cd "$app" && "$php" artisan goproperty:verify-package) || fail "verify-package failed after the upgrade"
[ "$(sql_value "select version from \`$cli_db\`.installation_state where id = 1")" = "$new_version" ] || fail "the database does not record $new_version"
fingerprint "$cli_db" "$last_audit" > "$work/cli-after.json"
diff -u "$work/cli-before.json" "$work/cli-after.json" || fail "data changed in the upgrade"
echo "data kept: $(grep -c '"id"' "$work/cli-after.json") rows compared"

server_pid=$(serve "$home" "127.0.0.1:$port" "$work/cli-server.log")
wait_for "$url/up"
admin_path=$(grep -E '^ADMIN_PATH=' "$app/.env" | cut -d= -f2)
expect_status "$url" / 200 "$work/cli-server.log"
expect_status "$url" /login 200 "$work/cli-server.log"
expect_status "$url" /install 404 "$work/cli-server.log"
expect_status "$url" "/$admin_path/login" 200 "$work/cli-server.log"
kill "$server_pid" && wait "$server_pid" 2>/dev/null || true
server_pid=""

step "SSH: the schema equals a plain migrate of the new release"
(cd "$app" && "$php" artisan goproperty:schema-snapshot) > "$work/cli-upgraded.json"
diff -u "$work/reference.json" "$work/cli-upgraded.json" || fail "the upgraded schema differs from a plain migrate"
echo "identical: $(grep -c '"name"' "$work/cli-upgraded.json") columns and tables compared"

step "SSH: roll back — swap the folders back and restore the backup (never migrate:rollback)"
artisan "$app" down --retry=60 > /dev/null
mv "$home/goproperty-app" "$home/goproperty-app.failed"
mv "$home/public_html" "$home/public_html.failed"
mv "$home/goproperty-app.previous" "$home/goproperty-app"
mv "$home/public_html.previous" "$home/public_html"
copy_database "$backup_db" "$cli_db"
artisan "$app" up > /dev/null
artisan "$app" schedule:run --no-ansi > /dev/null
artisan "$app" goproperty:check-installation > "$work/check.log" || { cat "$work/check.log"; fail "the previous release does not pass check-installation after the rollback"; }
[ "$(cat "$app/VERSION")" = "$previous_version" ] || fail "the rolled-back folder is not $previous_version"
[ "$(sql_value "select version from \`$cli_db\`.installation_state where id = 1")" = "$previous_version" ] || fail "the restored database does not record $previous_version"
fingerprint "$cli_db" > "$work/cli-rolled-back.json"
diff -u "$work/cli-before.json" "$work/cli-rolled-back.json" || fail "the rollback did not bring the data back as it was"
server_pid=$(serve "$home" "127.0.0.1:$port" "$work/cli-server.log")
wait_for "$url/up"
expect_status "$url" / 200 "$work/cli-server.log"
kill "$server_pid" && wait "$server_pid" 2>/dev/null || true
server_pid=""
echo "rolled back to $previous_version with its data"

# ---------------------------------------------------------------------------
# 6–9. Without SSH: the console's upgrade step over HTTP
# ---------------------------------------------------------------------------

jar="$work/web.cookies"
home="$work/web-home"
web_app="$home/goproperty-app"

# One request. Prints the status; the body is in $work/page and the
# Location header in $work/location.
web() {
    local method=$1 path=$2
    shift 2
    curl -s -o "$work/page" -D "$work/headers" -w '%{http_code}' -b "$jar" -c "$jar" \
        -X "$method" -H "Origin: $web_url" "$@" "$web_url$path"
    tr -d '\r' < "$work/headers" | sed -n 's/^[Ll]ocation: //p' | tail -1 > "$work/location"
}

web_get() {
    local path=$1 want=$2 code
    code=$(web GET "$path")
    if [ "$code" != "$want" ]; then
        head -c 1500 "$work/page"
        tail -40 "$web_app/storage/logs/laravel.log" 2>/dev/null || true
        fail "GET $path returned $code, expected $want"
    fi
}

# Posts a form with the CSRF token of the page last shown.
web_post() {
    local path=$1 want=$2 target=${3:-}
    shift 3
    local token code
    token=$(sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' "$work/page" | head -1)
    [ -n "$token" ] || token=$(sed -n 's/.*<meta name="csrf-token" content="\([^"]*\)".*/\1/p' "$work/page" | head -1)
    code=$(web POST "$path" --data-urlencode "_token=$token" "$@")
    if [ "$code" != "$want" ] || { [ -n "$target" ] && [[ $(cat "$work/location") != *"$target" ]]; }; then
        grep -o 'role="alert"[^>]*>[^<]*' "$work/page" | head -5 || true
        tail -40 "$web_app/storage/logs/laravel.log" 2>/dev/null || true
        fail "POST $path returned $code to '$(cat "$work/location")', expected $want ${target}"
    fi
}

# A TOTP code from a time step no earlier code used (each is accepted once).
fresh_code() {
    local wait=$(( 30 - $(date +%s) % 30 + 1 ))
    sleep "$wait"
    "$php" -r 'require $argv[1]; echo (new PragmaRX\Google2FA\Google2FA)->getCurrentOtp($argv[2]);' "$web_app/vendor/autoload.php" "$1"
}

step "No SSH: install the previous release ($previous_version) with the web wizard"
unpack_split "$previous_zip" "$home"
web_pid=$(serve "$home" "127.0.0.1:$web_port" "$work/web-server.log")
wait_for "$web_url/install.css"
web_get /install 200
phrase=$(sed -n 's/.*id="unlock-phrase">\([^<]*\)<.*/\1/p' "$work/page")
[ -n "$phrase" ] || fail "no unlock phrase on the first screen"
printf '%s\n' "$phrase" > "$web_app/storage/app/install-unlock.txt"
web_post /install/unlock/file 303 /install/resume
web_get /install/server 200
web_post /install/server 303 /install/site
web_get /install/site 200
web_post /install/site 303 /install/database \
    --data-urlencode action=save --data-urlencode "site_url=$web_url" --data-urlencode environment=production \
    --data-urlencode "db_driver=$DB_CONNECTION" --data-urlencode "db_host=$DB_HOST" --data-urlencode "db_port=$DB_PORT" \
    --data-urlencode "db_name=$web_db" --data-urlencode "db_user=$DB_USERNAME" --data-urlencode "db_password=$DB_PASSWORD"
web_get /install/database 200
for _ in $(seq 1 100); do
    web_post /install/database 303 ""
    [[ $(cat "$work/location") == *"/install/services" ]] && break
    web_get /install/database 200
    grep -q 'Migration .* failed' "$work/page" && fail "a migration of the previous release failed"
done
[[ $(cat "$work/location") == *"/install/services" ]] || fail "the previous release's database was not built"
web_get /install/services 200
web_post /install/services/mail 303 /install/services --data-urlencode action=skip
web_get /install/services 200
web_post /install/services/storage 303 /install/admin --data-urlencode action=skip
web_get /install/admin 200
admin_password="Drill-web-$suffix-passphrase"
web_post /install/admin 303 /install/admin \
    --data-urlencode name="Web Drill" --data-urlencode email=web-drill@example.com \
    --data-urlencode "password=$admin_password" --data-urlencode "password_confirmation=$admin_password"
web_get /install/admin 200
secret=$(sed -n 's/.*data-key="\([A-Z2-7]*\)".*/\1/p' "$work/page")
[ -n "$secret" ] || fail "no authenticator key on the enrolment page"
code=$("$php" -r 'require $argv[1]; echo (new PragmaRX\Google2FA\Google2FA)->getCurrentOtp($argv[2]);' "$web_app/vendor/autoload.php" "$secret")
web_post /install/admin/two-factor 303 /install/admin --data-urlencode "code=$code"
web_get /install/admin 200
web_post /install/admin/recovery-codes 303 /install/finish --data-urlencode saved=1
web_get /install/finish 200
web_post /install/finish 200 ""
grep -q 'GoProperty is installed' "$work/page" || fail "no finish screen"
web_admin_path=$(grep -E '^ADMIN_PATH=' "$web_app/.env" | cut -d= -f2)
(cd "$web_app" && "$php" "${limits[@]}" artisan schedule:run --no-ansi > /dev/null)
web_get "/$web_admin_path" 200
echo "installed $previous_version; the owner is signed in to the console"

step "No SSH: add data"
add_data "$web_db"
web_last_audit=$(sql_value "select coalesce(max(id), 0) from \`$web_db\`.activity_log")
fingerprint "$web_db" > "$work/web-before.json"

step "No SSH: swap in the new release ($new_version) with no command on the server"
kill "$web_pid" && wait "$web_pid" 2>/dev/null || true
web_pid=""
swap_in "$new_zip" "$home"
# One migration per request, so the drill sees the step carry on by itself.
printf '\nINSTALLER_MIGRATIONS_PER_BATCH=1\n' >> "$web_app/.env"
web_pid=$(serve "$home" "127.0.0.1:$web_port" "$work/web-server.log")
wait_for "$web_url/up"

step "No SSH: Console → Operations → Version"
web_get "/$web_admin_path/system/version" 200
grep -q "data-figure=\"version.current\">$new_version<" "$work/page" || fail "the Version page does not show $new_version"
grep -q "install seeders not run for $new_version" "$work/page" || fail "the Version page does not say the install seeders are pending"
pending=$(sed -n 's/.*data-figure="version.pending">\([0-9]*\)<.*/\1/p' "$work/page" | head -1)
echo "Version page: $previous_version → $new_version, $pending migrations pending"

step "No SSH: run pending migrations — confirm, then one request per step"
web_get "/$web_admin_path/system/upgrade" 200
grep -q 'name="backed_up"' "$work/page" || fail "the upgrade step does not ask for the backup tick"
code=$(fresh_code "$secret")
web_post "/$web_admin_path/system/upgrade/confirm" 303 "/$web_admin_path/system/upgrade" \
    --data-urlencode "password=$admin_password" --data-urlencode "code=$code" --data-urlencode backed_up=1
web_get "/$web_admin_path/system/upgrade" 200
grep -q 'id="upgrade-run"' "$work/page" || { grep -o 'role="alert"[^>]*>[^<]*' "$work/page" | head -3; fail "the step-up was not accepted"; }
requests=0
saw_maintenance=false
while :; do
    started=$(date +%s)
    web_post "/$web_admin_path/system/upgrade/run" 303 "/$web_admin_path/system/upgrade"
    requests=$((requests + 1))
    web_get "/$web_admin_path/system/upgrade" 200
    result=$(sed -n 's/.*data-upgrade-result="\([a-z_]*\)".*/\1/p' "$work/page" | head -1)
    echo "request $requests: $result in $(( $(date +%s) - started )) s — $(sed -n 's/.*<b data-progress>\([^<]*\)<.*/\1/p' "$work/page" | head -1) migrations done"
    case "$result" in
        finished) break ;;
        migrated)
            if [ "$saw_maintenance" = false ]; then
                code=$(curl -s -o /dev/null -w '%{http_code}' "$web_url/")
                [ "$code" = 503 ] || fail "visitors got $code while the upgrade ran, expected 503"
                saw_maintenance=true
                echo "visitors get 503 while it runs; the console answers"
            fi
            ;;
        *) grep -o 'data-upgrade-error[^<]*<' "$work/page" | head -3 || true
           fail "the upgrade step said '$result'" ;;
    esac
    [ "$requests" -lt 200 ] || fail "the upgrade did not finish after 200 requests"
done
[ "$requests" -ge $(( ${pending:-0} + 1 )) ] || fail "$requests requests for $pending migrations and the seeders"
grep -q "Upgraded to $new_version. Maintenance mode is off" "$work/page" || fail "the upgrade did not end with maintenance mode off"
[ ! -f "$web_app/storage/framework/down" ] || fail "the site is still in maintenance mode"

step "No SSH: the upgraded installation checks out"
expect_status "$web_url" / 200 "$work/web-server.log"
expect_status "$web_url" /install 404 "$work/web-server.log"
web_get "/$web_admin_path/system/version" 200
grep -q 'data-figure="version.nothing"' "$work/page" || fail "the Version page still shows something pending"
web_post "/$web_admin_path/system/upgrade/run" 303 "/$web_admin_path/system/upgrade"
web_get "/$web_admin_path/system/upgrade" 200
grep -q 'Nothing to run' "$work/page" || fail "a second run was not refused"
(cd "$web_app" && "$php" "${limits[@]}" artisan schedule:run --no-ansi > /dev/null)
(cd "$web_app" && "$php" "${limits[@]}" artisan goproperty:check-installation) || fail "check-installation failed after the web upgrade"
(cd "$web_app" && "$php" "${limits[@]}" artisan goproperty:audit-verify) || fail "the audit log chain is broken after the web upgrade"
[ -f "$web_app/bootstrap/cache/config.php" ] || fail "the upgrade step did not cache the config"
[ "$(sql_value "select version from \`$web_db\`.installation_state where id = 1")" = "$new_version" ] || fail "the database does not record $new_version"
[ "$(sql_value "select count(*) from \`$web_db\`.activity_log where log_name = 'operations' and event = 'upgrade.finished'")" = 1 ] || fail "the upgrade is not in the audit log"
fingerprint "$web_db" "$web_last_audit" > "$work/web-after.json"
diff -u "$work/web-before.json" "$work/web-after.json" || fail "data changed in the web upgrade"
(cd "$web_app" && "$php" artisan goproperty:schema-snapshot) > "$work/web-upgraded.json"
diff -u "$work/reference.json" "$work/web-upgraded.json" || fail "the web-upgraded schema differs from a plain migrate"
kill "$web_pid" && wait "$web_pid" 2>/dev/null || true
web_pid=""
for log in "$work/cli-server.log" "$work/web-server.log"; do
    if grep -qE 'PHP (Fatal|Warning)' "$log"; then
        cat "$log"
        fail "PHP warnings while serving"
    fi
done
echo "upgraded over HTTP in $requests requests; data kept; schema identical"

step "Upgrade drill passed ($previous_version → $new_version, $DB_CONNECTION)"
