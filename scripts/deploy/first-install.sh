#!/usr/bin/env bash
# First install on a server, straight into the deploy layout
# (docs/runbooks/shared-hosting.md § 2, INSTALLER.md): run once per
# environment, then every later release goes through remote-deploy.sh.
#
#   <app-dir>/releases/<name>/   the first release, unpacked from the archive
#   <app-dir>/shared/.env        written by `goproperty:install` through the
#                                release's .env symlink (mode 0600)
#   <app-dir>/shared/storage/    install markers, logs, sessions
#   <app-dir>/current            symlink to the release (document root: current/public)
#
# Usage: first-install.sh <app-dir> <archive.tar.gz> <release-name> [php-binary] [-- <goproperty:install options>]
#   e.g. first-install.sh ~/goproperty upload.tar.gz 20261027-abc1234 php -- --env=production --url=https://staging.goproperty.ng
# Without install options the installer asks its questions (needs a terminal).
set -Eeuo pipefail

if [ $# -lt 3 ]; then
    echo "Usage: $0 <app-dir> <archive.tar.gz> <release-name> [php-binary] [-- <goproperty:install options>]" >&2
    exit 64
fi

app_dir=$1
archive=$2
release=$3
shift 3
php=php
if [ $# -gt 0 ] && [ "$1" != "--" ]; then
    php=$1
    shift
fi
[ $# -gt 0 ] && [ "$1" = "--" ] && shift
install_args=("$@")

fail() {
    echo "First install failed: $*" >&2
    exit 1
}

[[ $release =~ ^[A-Za-z0-9._-]+$ ]] || fail "release name '$release' may only use letters, digits, '.', '_' and '-'"
[ -f "$archive" ] || fail "archive $archive not found"
mkdir -p "$app_dir"
app_dir=$(cd "$app_dir" && pwd -P)
[ ! -e "$app_dir/current" ] || fail "$app_dir/current exists: this server is installed already; deploy with remote-deploy.sh"

release_dir="$app_dir/releases/$release"
[ ! -e "$release_dir" ] || fail "release $release already exists"

mkdir -p "$app_dir/releases" \
    "$app_dir/shared/storage/app/private" \
    "$app_dir/shared/storage/app/public" \
    "$app_dir/shared/storage/framework/cache/data" \
    "$app_dir/shared/storage/framework/sessions" \
    "$app_dir/shared/storage/framework/views" \
    "$app_dir/shared/storage/logs"
chmod 700 "$app_dir/shared"
(umask 077 && touch "$app_dir/shared/.env")

echo "Unpacking $release"
mkdir "$release_dir"
tar -xzf "$archive" -C "$release_dir"
ln -sfn ../../shared/.env "$release_dir/.env"
rm -rf "$release_dir/storage"
ln -sfn "$app_dir/shared/storage" "$release_dir/storage"

echo "Installing (goproperty:install)"
(cd "$release_dir" && "$php" artisan goproperty:install "${install_args[@]}") || fail "goproperty:install did not finish; nothing is live. Fix the problem and run this again with a new release name, or see docs/runbooks/install-recovery.md"

[ -L "$release_dir/.env" ] || fail "the installer replaced the .env link; shared/.env would be out of date"
grep -q '^APP_KEY=.\+' "$app_dir/shared/.env" || fail "shared/.env has no APP_KEY after the install"

ln -sfn "releases/$release" "$app_dir/current.next"
mv -Tf "$app_dir/current.next" "$app_dir/current"
rm -f "$archive"

echo "current -> releases/$release"
echo "Installed. Point the document root at $app_dir/current/public and add the cron line the installer printed."
