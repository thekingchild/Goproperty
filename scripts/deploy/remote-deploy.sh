#!/usr/bin/env bash
# Runs on the shared host (over SSH) to install one release built by
# scripts/deploy/build-release.sh. Layout, from docs/runbooks/shared-hosting.md § 2:
#
#   <app-dir>/releases/<name>/   one directory per deploy
#   <app-dir>/shared/.env        secrets, written by the installer
#   <app-dir>/shared/storage/    logs, cache, sessions
#   <app-dir>/current            symlink to the live release (document root: current/public)
#
# Usage: remote-deploy.sh <app-dir> <archive.tar.gz> <release-name> [php-binary]
# Env:   MAINTENANCE=1     maintenance mode around migrations (schema-breaking releases)
#        BACKUP_FIRST=1    back up the database before migrating; a failed backup stops the deploy
#        KEEP_RELEASES=5   releases kept for rollback
set -Eeuo pipefail

if [ $# -lt 3 ] || [ $# -gt 4 ]; then
    echo "Usage: $0 <app-dir> <archive.tar.gz> <release-name> [php-binary]" >&2
    exit 64
fi

app_dir=$(cd "$1" && pwd -P)
archive=$2
release=$3
php=${4:-php}
keep=${KEEP_RELEASES:-5}
maintenance=${MAINTENANCE:-0}
backup_first=${BACKUP_FIRST:-0}
down=0

fail() {
    echo "Deploy failed: $*" >&2
    exit 1
}

on_error() {
    echo "Deploy failed. 'current' still points to the previous release." >&2
    if [ "$down" = 1 ]; then
        echo "The site is still in maintenance mode; run 'php artisan up' from current/ once it is safe." >&2
    fi
}
trap on_error ERR

[[ $release =~ ^[A-Za-z0-9._-]+$ ]] || fail "release name '$release' may only use letters, digits, '.', '_' and '-'"
[[ $keep =~ ^[1-9][0-9]*$ ]] || fail "KEEP_RELEASES must be a positive number"
[ -f "$archive" ] || fail "archive $archive not found"
[ -f "$app_dir/shared/.env" ] || fail "$app_dir/shared/.env is missing; install the application first (INSTALLER.md)"

release_dir="$app_dir/releases/$release"
[ ! -e "$release_dir" ] || fail "release $release already exists"

mkdir -p "$app_dir/releases" \
    "$app_dir/shared/storage/app/public" \
    "$app_dir/shared/storage/framework/cache/data" \
    "$app_dir/shared/storage/framework/sessions" \
    "$app_dir/shared/storage/framework/views" \
    "$app_dir/shared/storage/logs"

echo "Unpacking $release"
mkdir "$release_dir"
tar -xzf "$archive" -C "$release_dir"
rm -f "$archive"
ln -sfn "$app_dir/shared/.env" "$release_dir/.env"
rm -rf "$release_dir/storage"
ln -sfn "$app_dir/shared/storage" "$release_dir/storage"

cd "$release_dir"
artisan() {
    "$php" artisan "$@" --no-interaction
}

# Refuse a release whose hosting settings cannot work together, before
# anything changes (ARCHITECTURE.md § 12.1).
artisan goproperty:hosting

# A restorable copy of the data from just before this release's migrations
# (INSTALLER.md § 10, docs/runbooks/restore.md).
if [ "$backup_first" = 1 ]; then
    echo "Backing up the database before migrating"
    artisan goproperty:backup-run db
fi

if [ "$maintenance" = 1 ]; then
    artisan down --retry=60
    down=1
fi

artisan migrate --force
# Idempotent install seeders: roles gain the permissions a release adds.
artisan db:seed --class='Database\Seeders\InstallSeeder' --force

# Build the caches in the new release before it goes live.
artisan config:cache
artisan route:cache
artisan view:cache
artisan event:cache

# Atomic switch: rename a fresh symlink over the old one.
ln -sfn "releases/$release" "$app_dir/current.next"
mv -Tf "$app_dir/current.next" "$app_dir/current"
echo "current -> releases/$release"

# Daemon workers (VPS) reload; the shared-hosting queue tick starts fresh each minute anyway.
artisan queue:restart

if [ "$down" = 1 ]; then
    artisan up
    down=0
fi

# Keep the newest $keep releases. Names start with a UTC timestamp, so they sort by age.
cd "$app_dir/releases"
find . -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -r | tail -n +"$((keep + 1))" | while read -r old; do
    if [ "$old" != "$release" ]; then
        rm -rf -- "$old"
        echo "Removed old release $old"
    fi
done

echo "Deployed $release"
