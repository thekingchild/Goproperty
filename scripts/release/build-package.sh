#!/usr/bin/env bash
# Builds the release zip for installing from a file upload (INSTALLER.md § 2,
# docs/runbooks/release.md):
#
#   goproperty-<version>.zip
#     README.txt
#     preferred/goproperty/       document root → goproperty/public
#     split/goproperty-app/       app outside the web root, for hosts whose
#     split/public_html/          domain must use public_html (public files only)
#   goproperty-<version>.zip.sha256
#
# Each layout carries VERSION and SHA256SUMS (every file except storage/ and
# bootstrap/cache/, public files listed as public/…), which
# `php artisan goproperty:verify-package` checks.
#
# Usage: scripts/release/build-package.sh <version> <output-dir>
set -euo pipefail

if [ $# -ne 2 ]; then
    echo "Usage: $0 <version> <output-dir>" >&2
    exit 64
fi

version=$1
[[ $version =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]] \
    || { echo "Version '$version' must look like 1.4.0 or 1.4.0-rc.1" >&2; exit 64; }

out=$(realpath -m "$2")
root=$(cd "$(dirname "$0")/../.." && pwd)
name="goproperty-$version"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

"$root/scripts/release/stage.sh" "$work/app"

cd "$work/app"
# Laravel rebuilds its package caches on first boot; shipping them would put
# unchecked PHP in a folder SHA256SUMS does not cover.
find bootstrap/cache storage -type f ! -name .gitignore -delete
printf '%s\n' "$version" > VERSION

# Lists every file of a layout as "<sha256>  <path>", public files as public/….
# Usage: write_sums <app-dir> <public-dir>
write_sums() {
    local app=$1 public=$2
    {
        (cd "$app" && find . -type f ! -path ./SHA256SUMS ! -path './storage/*' \
            ! -path './bootstrap/cache/*' ! -path './public/*' -print0 \
            | xargs -0 sha256sum | sed 's#  \./#  #')
        (cd "$public" && find . -type f -print0 | xargs -0 sha256sum | sed 's#  \./#  public/#')
    } | LC_ALL=C sort -k 2 > "$app/SHA256SUMS"

    # sha256sum escapes odd file names with a leading backslash; the verifier
    # only accepts plain paths, so refuse to ship them.
    if grep -q '^\\' "$app/SHA256SUMS"; then
        echo "Some file names contain backslashes or new lines:" >&2
        grep '^\\' "$app/SHA256SUMS" >&2
        exit 1
    fi
}

pkg="$work/package"
mkdir -p "$pkg/preferred" "$pkg/split"

# Preferred: the panel points the domain at goproperty/public.
cp -a "$work/app" "$pkg/preferred/goproperty"
write_sums "$pkg/preferred/goproperty" "$pkg/preferred/goproperty/public"

# Split: public files in public_html/, the app beside it in goproperty-app/.
cp -a "$work/app" "$pkg/split/goproperty-app"
mv "$pkg/split/goproperty-app/public" "$pkg/split/public_html"
sed -e "s#__DIR__\.'/\.\./#__DIR__.'/../goproperty-app/#g" \
    -e 's#^\$app->handleRequest(#// Public files live here, not in goproperty-app/public (INSTALLER.md § 2).\n$app->usePublicPath(__DIR__);\n\n$app->handleRequest(#' \
    "$work/app/public/index.php" > "$pkg/split/public_html/index.php"
for expected in "goproperty-app/bootstrap/php-version.php" "goproperty-app/vendor/autoload.php" 'usePublicPath(__DIR__)'; do
    grep -qF "$expected" "$pkg/split/public_html/index.php" \
        || { echo "public/index.php changed shape; update the split rewrite in $0 ($expected)" >&2; exit 1; }
done
cat > "$pkg/split/goproperty-app/bootstrap/public-path.php" <<'PHP'
<?php

// Split layout (INSTALLER.md § 2): public files live in the sibling
// public_html/ folder. Edit this if the host names that folder differently.
return dirname(__DIR__, 2).'/public_html';
PHP
write_sums "$pkg/split/goproperty-app" "$pkg/split/public_html"

cat > "$pkg/README.txt" <<TXT
GoProperty $version

Upload ONE of the two layouts, then open https://your-domain/install.

preferred/goproperty/
    Use this whenever your hosting panel lets you change the domain's
    document root. Upload the goproperty folder next to public_html and
    point the document root at goproperty/public.

split/goproperty-app/ + split/public_html/
    Use this when the domain must use public_html. Upload goproperty-app
    next to public_html, and put the contents of split/public_html into
    public_html.

Before installing, compare the zip's SHA-256 checksum with the
.sha256 file on the release page. After uploading, over SSH:

    php artisan goproperty:verify-package

Full guide: INSTALLER.md and docs/runbooks/release.md in the repository.
TXT

# Same timestamps and entry order on every build of the same commit.
epoch=$(git -C "$root" log -1 --format=%ct)
find "$pkg" -exec touch -h -d "@$epoch" {} +

mkdir -p "$out"
rm -f "$out/$name.zip" "$out/$name.zip.sha256"
(cd "$pkg" && find README.txt preferred split | LC_ALL=C sort | zip -q -X -@ "$out/$name.zip")
(cd "$out" && sha256sum "$name.zip" > "$name.zip.sha256")

echo "Built $out/$name.zip ($(du -h "$out/$name.zip" | cut -f1))"
cat "$out/$name.zip.sha256"
