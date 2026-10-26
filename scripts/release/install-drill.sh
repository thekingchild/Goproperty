#!/usr/bin/env bash
# Install drill (P00-06, P01-09, INSTALLER.md § 12): installs a built release
# zip the way an owner without root would, twice: from the command line and
# through the web wizard over HTTP. Both under shared-host limits:
# max_execution_time=30, memory_limit=256M, exec/proc_open and pcntl disabled.
#
# Command line:
#   1. unpack the split layout (goproperty-app/ + public_html/)
#   2. php artisan goproperty:install --no-interaction
#   3. a second install is refused; cron runs once; check-installation passes;
#      the audit log hash chain verifies
#   4. the site serves pages from public_html; /install is 404; the console
#      sign-in answers at /{ADMIN_PATH}, which robots.txt never names
#   5. the installed copy still passes goproperty:verify-package
#
# Web wizard, with curl against `php -S` on a second split layout:
#   6. file-check unlock (the phrase written to storage/app/install-unlock.txt),
#      server check, site & database, the migrations batch by batch, a test
#      email to a local SMTP sink, storage skipped, the first super-admin
#      with TOTP enrolment and recovery codes, finish
#   7. the finish screen shows the console path from .env, the cron line and
#      the installation record with APP_KEY; the owner is signed in to the
#      console; after one cron run its heartbeat says the scheduler ran
#   8. /install and every wizard route are 404; check-installation passes;
#      the audit log verifies
#
# Then both schemas must equal a plain `php artisan migrate` of the release.
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
web_db="gp_drill_web_$suffix"
reference_db="gp_drill_reference_$suffix"
port=$((18800 + RANDOM % 1000))
url="http://127.0.0.1:$port"
web_port=$((19800 + RANDOM % 1000))
# localhost, not 127.0.0.1: curl sends the console's Secure cookie over
# plain http only to localhost.
web_url="http://localhost:$web_port"
smtp_port=$((20800 + RANDOM % 1000))
server_pid=""
web_pid=""
smtp_pid=""

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
    [ -z "$web_pid" ] || kill "$web_pid" 2>/dev/null || true
    [ -z "$smtp_pid" ] || kill "$smtp_pid" 2>/dev/null || true
    sql "drop database if exists \`$install_db\`" || true
    sql "drop database if exists \`$web_db\`" || true
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
(cd "$app" && "$php" "${limits[@]}" artisan goproperty:audit-verify) || fail "the audit log chain is broken after the install"

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
expect_status "/$admin_path/login" 200
expect_status "/$admin_path" 302
expect_status /admin 404
expect_status /robots.txt 200
grep -q "Disallow: /login" "$work/body" || fail "robots.txt does not keep crawlers off /login"
! grep -q "$admin_path" "$work/body" || fail "robots.txt names the console path"
kill "$server_pid" && wait "$server_pid" 2>/dev/null || true
server_pid=""
if grep -qE 'PHP (Fatal|Warning)' "$work/server.log"; then
    cat "$work/server.log"
    fail "PHP warnings while serving"
fi

step "The installed copy still verifies"
(cd "$app" && "$php" artisan goproperty:verify-package) || fail "verify-package failed after the install"

# ---------------------------------------------------------------------------
# The web wizard over HTTP (P01-09)
# ---------------------------------------------------------------------------

jar="$work/web.cookies"
web_app="$work/web-home/goproperty-app"

# One request to the wizard. Prints the status; the body is in $work/page
# and the Location header in $work/location.
web() {
    local method=$1 path=$2
    shift 2
    curl -s -o "$work/page" -D "$work/headers" -w '%{http_code}' -b "$jar" -c "$jar" \
        -X "$method" -H "Origin: $web_url" "$@" "$web_url$path"
    tr -d '\r' < "$work/headers" | sed -n 's/^[Ll]ocation: //p' | tail -1 > "$work/location"
}

# Posts a form with the CSRF token of the page last shown, and checks the
# status (and the redirect target when given).
web_post() {
    local path=$1 want=$2 target=${3:-}
    shift 3
    local token code
    token=$(sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' "$work/page" | head -1)
    code=$(web POST "$path" --data-urlencode "_token=$token" "$@")
    if [ "$code" != "$want" ] || { [ -n "$target" ] && [[ $(cat "$work/location") != *"$target" ]]; }; then
        grep -o 'class="alert[^"]*"[^>]*>[^<]*' "$work/page" | head -5 || true
        tail -40 "$web_app/storage/logs/laravel.log" 2>/dev/null || true
        fail "POST $path returned $code to '$(cat "$work/location")', expected $want ${target}"
    fi
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

step "Web install: unpack a second split layout"
mkdir -p "$work/web-unzipped" "$work/web-home"
unzip -q "$zip" 'split/*' -d "$work/web-unzipped"
mv "$work/web-unzipped/split/goproperty-app" "$work/web-unzipped/split/public_html" "$work/web-home/"
sql "create database \`$web_db\` character set utf8mb4 collate utf8mb4_unicode_ci"
[ ! -e "$web_app/.env" ] || fail "the package ships a .env"

"$php" "${limits[@]}" -S "127.0.0.1:$web_port" -t "$work/web-home/public_html" > "$work/web-server.log" 2>&1 &
web_pid=$!
"$php" "$(dirname "$0")/smtp-sink.php" "$smtp_port" "$work/mailbox.txt" > "$work/smtp.log" 2>&1 &
smtp_pid=$!
for _ in $(seq 1 50); do
    curl -s -o /dev/null "$web_url/install.css" && break
    sleep 0.2
done

step "Web install: unlock with the file check"
web_get /install 200
grep -q 'Unlock with a file (no SSH)' "$work/page" || fail "the first screen has no file check"
! grep -qi '^Set-Cookie' "$work/headers" || fail "the first screen set a cookie"
phrase=$(sed -n 's/.*id="unlock-phrase">\([^<]*\)<.*/\1/p' "$work/page")
[[ $phrase =~ ^GP-UNLOCK\ [A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$ ]] || fail "no unlock phrase on the first screen ('$phrase')"
printf '%s\n' "$phrase" > "$web_app/storage/app/install-unlock.txt"
web_post /install/unlock/file 303 /install/resume
[ ! -e "$web_app/storage/app/install-unlock.txt" ] || fail "the unlock file was not deleted"
grep -qE '^APP_KEY="?base64:' "$web_app/.env" || fail "the unlock did not create .env with APP_KEY"
[ "$(stat -c %a "$web_app/.env")" = 600 ] || fail ".env is not mode 600"
echo "unlocked; .env created with APP_KEY (mode 600)"

step "Web install: server check, site and database"
web_get /install/resume 303
web_get /install/server 200
grep -q 'PHP extension: pdo_mysql' "$work/page" || fail "no detailed server report"
web_post /install/server 303 /install/site
web_get /install/site 200
web_post /install/site 303 /install/database \
    --data-urlencode action=save --data-urlencode "site_url=$web_url" --data-urlencode environment=production \
    --data-urlencode "db_driver=$DB_CONNECTION" --data-urlencode "db_host=$DB_HOST" --data-urlencode "db_port=$DB_PORT" \
    --data-urlencode "db_name=$web_db" --data-urlencode "db_user=$DB_USERNAME" --data-urlencode "db_password=$DB_PASSWORD"

step "Web install: build the database, one batch per request"
web_get /install/database 200
batches=0
while :; do
    started=$(date +%s)
    web_post /install/database 303 ""
    batches=$((batches + 1))
    echo "batch $batches: $(( $(date +%s) - started )) s"
    [[ $(cat "$work/location") == *"/install/services" ]] && break
    [ "$batches" -lt 100 ] || fail "the database was not built after 100 batches"
    web_get /install/database 200
    grep -q 'Migration .* failed' "$work/page" && fail "a migration failed: $(grep -o 'Migration [^<]*' "$work/page" | head -1)"
    sed -n 's/.*<b data-progress>\([^<]*\)<.*/  \1/p' "$work/page"
done

step "Web install: test email, storage skipped"
web_get /install/services 200
web_post /install/services/mail 303 /install/services \
    --data-urlencode action=test --data-urlencode mail_provider=smtp --data-urlencode mail_host=127.0.0.1 \
    --data-urlencode "mail_port=$smtp_port" --data-urlencode mail_username= --data-urlencode mail_from=no-reply@example.com \
    --data-urlencode mail_test=drill@example.com
grep -q '^To: drill@example.com' "$work/mailbox.txt" || fail "the test email did not arrive"
web_get /install/services 200
web_post /install/services/storage 303 /install/admin --data-urlencode action=skip

step "Web install: first super-admin with an authenticator app"
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
[ "$(grep -cE '<li>[A-Za-z0-9]{10}-[A-Za-z0-9]{10}</li>' "$work/page")" = 8 ] || fail "not 8 recovery codes"
web_post /install/admin/recovery-codes 303 /install/finish --data-urlencode saved=1
echo "super-admin enrolled with TOTP; 8 recovery codes shown"

step "Web install: finish"
web_get /install/finish 200
started=$(date +%s)
web_post /install/finish 200 ""
echo "finish took $(( $(date +%s) - started )) s"
cp "$work/page" "$work/finished.html"
web_admin_path=$(grep -E '^ADMIN_PATH=' "$web_app/.env" | cut -d= -f2)
[[ $web_admin_path =~ ^gp-control-[a-z0-9]{6}$ ]] || fail "ADMIN_PATH is '$web_admin_path'"
grep -q 'GoProperty is installed' "$work/finished.html" || fail "no finish screen"
grep -q "$web_url/$web_admin_path" "$work/finished.html" || fail "the finish screen does not show the console path"
grep -q 'artisan schedule:run' "$work/finished.html" || fail "the finish screen has no cron line"
grep -q 'Waiting for the first scheduler run' "$work/finished.html" || fail "the finish screen has no live cron check"
record=$(sed -n 's/.*id="record" download="goproperty-installation-record.txt" href="data:text\/plain;charset=utf-8;base64,\([^"]*\)".*/\1/p' "$work/finished.html" | base64 -d)
app_key=$(sed -n 's/^APP_KEY="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' "$web_app/.env")
grep -qF "APP_KEY:        $app_key" <<< "$record" || fail "the installation record lacks APP_KEY"
[ -f "$web_app/storage/app/installed.lock" ] || fail "no installed.lock"
grep -q '^SESSION_DRIVER=database' "$web_app/.env" || fail "sessions were not moved to the database"
grep -q 'gp_staff_session' "$jar" || fail "no console session was handed over"

step "Web install: the owner is in the console; cron turns the heartbeat green"
web_get "/$web_admin_path" 200
web_get "/$web_admin_path/system/heartbeat" 200
grep -q '"scheduler":{"at":null,"fresh":false}' "$work/page" || fail "the heartbeat says the scheduler ran before cron did: $(cat "$work/page")"
(cd "$web_app" && "$php" "${limits[@]}" artisan schedule:run --no-ansi > /dev/null)
web_get "/$web_admin_path/system/heartbeat" 200
grep -q '"fresh":true' "$work/page" || fail "the heartbeat did not turn green after cron ran: $(cat "$work/page")"
echo "console signed in; heartbeat fresh after one cron run"

step "Web install: /install is closed for good"
for path in /install /install/server /install/site /install/database /install/finish /install/resume; do
    web_get "$path" 404
done
code=$(web POST /install/unlock/file) && [ "$code" = 404 ] || fail "POST /install/unlock/file returned $code"
code=$(web POST /install/finish) && [ "$code" = 404 ] || fail "POST /install/finish returned $code"
echo "every installer route answers 404"
(cd "$web_app" && "$php" "${limits[@]}" artisan goproperty:check-installation) || fail "check-installation failed after the web install"
(cd "$web_app" && "$php" "${limits[@]}" artisan goproperty:audit-verify) || fail "the audit log chain is broken after the web install"
kill "$web_pid" && wait "$web_pid" 2>/dev/null || true
web_pid=""
if grep -qE 'PHP (Fatal|Warning)' "$work/web-server.log"; then
    cat "$work/web-server.log"
    fail "PHP warnings while serving the wizard"
fi

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
(cd "$web_app" && "$php" artisan goproperty:schema-snapshot) > "$work/web-installed.json"
grep -q '"platform_staff"' "$work/installed.json" || fail "the snapshot holds no tables"
diff -u "$work/reference.json" "$work/installed.json" || fail "the installed schema differs from a plain migrate"
diff -u "$work/reference.json" "$work/web-installed.json" || fail "the web-installed schema differs from a plain migrate"
echo "identical (command line and web): $(grep -c '"name"' "$work/installed.json") columns and tables compared"

step "Install drill passed"
