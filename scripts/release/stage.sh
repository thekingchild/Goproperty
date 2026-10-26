#!/usr/bin/env bash
# Copies the application into <dir> and builds it for production: vendor/
# without dev packages, and the Vite assets. Dev tooling, tests and docs are
# removed. Shared by the deploy archive (scripts/deploy/build-release.sh) and
# the release zip (scripts/release/build-package.sh), so the server needs
# neither Composer nor Node (INSTALLER.md § 2).
#
# Usage: scripts/release/stage.sh <dir>     (<dir> must not exist yet)
set -euo pipefail

if [ $# -ne 1 ]; then
    echo "Usage: $0 <dir>" >&2
    exit 64
fi

dest=$(realpath -m "$1")
[ ! -e "$dest" ] || { echo "$dest already exists" >&2; exit 1; }
root=$(cd "$(dirname "$0")/../.." && pwd)
mkdir -p "$dest"

# Tracked and new (not ignored) files only, so .env, vendor/, node_modules/
# and local storage never ship from the build machine.
(cd "$root" && git ls-files -z --cached --others --exclude-standard) \
    | (cd "$root" && xargs -0 tar -cf - --no-recursion) \
    | tar -xf - -C "$dest"

cd "$dest"
composer install --no-dev --optimize-autoloader \
    --no-interaction --no-progress --prefer-dist
npm ci --no-audit --no-fund
npm run build

rm -rf node_modules tests docs .github scripts \
    .editorconfig .gitattributes .gitignore .npmrc \
    compose.yaml phpstan.neon phpunit.xml pint.json package.json package-lock.json vite.config.js \
    ./*.md
