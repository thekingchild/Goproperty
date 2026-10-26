#!/usr/bin/env bash
# Release drill (P00-04, INSTALLER.md § 2): checks a built release zip the way
# an owner would use it, with the package's own artisan.
#
#   1. goproperty:verify-package passes on the zip and on both unpacked layouts
#   2. a changed file, an added file and a changed zip entry all fail it
#   3. both layouts, served with `php -S`, send a fresh upload to /install,
#      say "being set up" once APP_KEY exists, and serve built assets
#   4. with OLD_PHP_IMAGES set (Docker images, e.g. "php:7.4-cli php:8.1-cli"),
#      artisan and index.php print the old-PHP sentence instead of an error
#
# Usage: scripts/release/verify-drill.sh <goproperty-x.y.z.zip>
# Env:   PHP=php              PHP 8.4 binary
#        OLD_PHP_IMAGES=""    Docker images for the old-PHP check
set -euo pipefail

if [ $# -ne 1 ]; then
    echo "Usage: $0 <goproperty-x.y.z.zip>" >&2
    exit 64
fi

zip=$(realpath "$1")
php=${PHP:-php}
work=$(mktemp -d)
server_pid=""

cleanup() {
    [ -z "$server_pid" ] || kill "$server_pid" 2>/dev/null || true
    rm -rf "$work"
}
trap cleanup EXIT

step() { printf '\n==> %s\n' "$*"; }
fail() { echo "DRILL FAILED: $*" >&2; exit 1; }

# Runs a command that must fail and print the given text.
expect_failure() {
    local text=$1 output
    shift
    if output=$("$@" 2>&1); then
        echo "$output"
        fail "expected a failure mentioning: $text"
    fi
    grep -qF -- "$text" <<< "$output" || { echo "$output"; fail "output does not mention: $text"; }
    echo "refused as expected: $text"
}

pkg="$work/pkg"
preferred="$pkg/preferred/goproperty"
split="$pkg/split/goproperty-app"
public_html="$pkg/split/public_html"

step "Verify the zip with the package's own artisan"
mkdir "$pkg"
unzip -q "$zip" -d "$pkg"
"$php" "$preferred/artisan" goproperty:verify-package "$zip"

step "Verify both unpacked layouts"
(cd "$preferred" && "$php" artisan goproperty:verify-package)
(cd "$split" && "$php" artisan goproperty:verify-package)

step "A changed file fails"
cp "$preferred/public/robots.txt" "$work/robots.txt"
echo "Disallow: /changed" >> "$preferred/public/robots.txt"
expect_failure "public/robots.txt does not match its SHA256SUMS checksum" \
    "$php" "$preferred/artisan" goproperty:verify-package
cp "$work/robots.txt" "$preferred/public/robots.txt"

step "An added file fails"
echo '<?php echo 1;' > "$public_html/added.php"
expect_failure "public/added.php is not listed in SHA256SUMS" \
    "$php" "$split/artisan" goproperty:verify-package
rm "$public_html/added.php"

step "A changed zip entry fails"
mkdir -p "$work/tampered/preferred/goproperty/public"
cp "$zip.sha256" "$work/tampered/" 2>/dev/null || true
cp "$zip" "$work/tampered/"
echo "Disallow: /changed" >> "$work/robots.txt"
cp "$work/robots.txt" "$work/tampered/preferred/goproperty/public/robots.txt"
(cd "$work/tampered" && zip -q "$(basename "$zip")" preferred/goproperty/public/robots.txt)
expect_failure "preferred: public/robots.txt does not match its SHA256SUMS checksum" \
    "$php" "$preferred/artisan" goproperty:verify-package "$work/tampered/$(basename "$zip")"
if [ -f "$zip.sha256" ]; then
    expect_failure "does not match $(basename "$zip").sha256" \
        "$php" "$preferred/artisan" goproperty:verify-package "$work/tampered/$(basename "$zip")"
fi

# Requests a page from the layout being served and checks the status, and
# optionally text in the headers or body. Uses serve_check's $url.
# Usage: expect_status <method> <path> <status> [text]
expect_status() {
    local method=$1 path=$2 want=$3 text=${4:-} code
    code=$(curl -s -D "$work/headers" -o "$work/body" -w '%{http_code}' -X "$method" "$url$path")
    if [ "$code" != "$want" ] || { [ -n "$text" ] && ! cat "$work/headers" "$work/body" | grep -qF -- "$text"; }; then
        cat "$work/headers" "$work/body" | head -40
        fail "$method $path returned $code, expected $want ${text}"
    fi
    echo "$method $path: $code ${text}"
}

# Serves a layout with the built-in server, first as a fresh upload and then
# with APP_KEY, and checks the installer gates (INSTALLER.md § 3) and assets.
# Usage: serve_check <app-dir> <public-dir> <port>
serve_check() {
    local app=$1 public=$2 port=$3 asset
    local url="http://127.0.0.1:$port"

    "$php" -S "127.0.0.1:$port" -t "$public" > "$work/server-$port.log" 2>&1 &
    server_pid=$!
    for _ in $(seq 1 50); do
        curl -s -o /dev/null "$url/install.css" && break
        sleep 0.2
    done

    # Fresh upload: no .env and no APP_KEY.
    expect_status GET / 302 "Location: /install"
    expect_status GET /login 302 "Location: /install"
    expect_status POST /login 503 "being set up"
    expect_status GET /install 200 "Install GoProperty"
    expect_status GET /install.css 200

    # With APP_KEY but not installed: every public page says "being set up".
    printf 'APP_KEY=\nAPP_ENV=production\nAPP_DEBUG=false\nAPP_URL=%s\n' "$url" > "$app/.env"
    "$php" "$app/artisan" key:generate --force --no-interaction > /dev/null
    expect_status GET /up 200
    expect_status GET / 503 "is being set up"
    expect_status GET /install 200 "Install GoProperty"

    asset=$("$php" -r '$m = json_decode(file_get_contents($argv[1]), true); echo "/build/", reset($m)["file"];' "$public/build/manifest.json")
    expect_status GET "$asset" 200

    if find "$app/storage/logs" -type f ! -name .gitignore | grep -q .; then
        cat "$app"/storage/logs/*.log | tail -40
        fail "the layout wrote to its log"
    fi

    kill "$server_pid" && wait "$server_pid" 2>/dev/null || true
    server_pid=""
    rm -f "$app/.env"
}

step "The preferred layout serves pages"
serve_check "$preferred" "$preferred/public" 18741

step "The split layout serves pages from public_html"
serve_check "$split" "$public_html" 18742

step "Both layouts still verify after running (.env, storage and caches are not package files)"
(cd "$preferred" && "$php" artisan goproperty:verify-package)
(cd "$split" && "$php" artisan goproperty:verify-package)

for image in ${OLD_PHP_IMAGES:-}; do
    step "Old-PHP guard on $image"
    output=$(docker run --rm -v "$pkg:/pkg:ro" "$image" php /pkg/preferred/goproperty/artisan --version 2>&1) \
        && fail "artisan exited 0 on $image"
    grep -qE '^GoProperty needs PHP 8\.4\.1 or newer; this server runs [0-9]+\.[0-9]+\.[0-9]+\.$' <<< "$output" \
        || { echo "$output"; fail "artisan did not print the old-PHP sentence on $image"; }
    echo "artisan: $output"

    for docroot in preferred/goproperty/public split/public_html; do
        container=$(docker run -d --rm -p 127.0.0.1:18743:8000 -v "$pkg:/pkg:ro" "$image" \
            php -S 0.0.0.0:8000 -t "/pkg/$docroot")
        code=000
        for _ in $(seq 1 50); do
            code=$(curl -s -o "$work/body" -w '%{http_code}' http://127.0.0.1:18743/ || true)
            [ "$code" = 000 ] || break
            sleep 0.2
        done
        docker stop "$container" > /dev/null
        [ "$code" = 500 ] && grep -q 'GoProperty needs PHP 8.4.1 or newer' "$work/body" \
            || { cat "$work/body"; fail "$docroot/index.php on $image returned $code without the sentence"; }
        echo "$docroot/index.php: 500, $(cat "$work/body")"
    done
done

step "Release drill passed"
