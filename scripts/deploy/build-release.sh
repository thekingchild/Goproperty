#!/usr/bin/env bash
# Builds the shared-hosting deploy archive: production vendor/ and built Vite
# assets, without dev tooling, tests, storage or secrets. The server needs
# neither Composer nor Node (docs/runbooks/shared-hosting.md § 3).
#
# Usage: scripts/deploy/build-release.sh <output.tar.gz>
set -euo pipefail

if [ $# -ne 1 ]; then
    echo "Usage: $0 <output.tar.gz>" >&2
    exit 64
fi

output=$(realpath -m "$1")
mkdir -p "$(dirname "$output")"
root=$(cd "$(dirname "$0")/../.." && pwd)
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT

# Tracked and new (not ignored) files only, so .env, vendor/, node_modules/
# and local storage never ship from the build machine.
(cd "$root" && git ls-files -z --cached --others --exclude-standard) \
    | (cd "$root" && xargs -0 tar -cf - --no-recursion) \
    | tar -xf - -C "$stage"

cd "$stage"
composer install --no-dev --optimize-autoloader \
    --no-interaction --no-progress --prefer-dist
npm ci --no-audit --no-fund
npm run build

# The release links shared/storage and shared/.env on the server.
rm -rf node_modules storage tests docs .github scripts \
    .editorconfig .env.example .gitattributes .gitignore .npmrc \
    compose.yaml phpstan.neon phpunit.xml pint.json package.json package-lock.json vite.config.js \
    ./*.md

tar -czf "$output" -C "$stage" .
echo "Built $output ($(du -h "$output" | cut -f1))"
