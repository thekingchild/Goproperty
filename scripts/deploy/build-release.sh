#!/usr/bin/env bash
# Builds the shared-hosting deploy archive: production vendor/ and built Vite
# assets (scripts/release/stage.sh), without storage or secrets. The server
# needs neither Composer nor Node (docs/runbooks/shared-hosting.md § 3).
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
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

"$root/scripts/release/stage.sh" "$work/app"

# The release links shared/storage and shared/.env on the server.
rm -rf "$work/app/storage" "$work/app/.env.example"

tar -czf "$output" -C "$work/app" .
echo "Built $output ($(du -h "$output" | cut -f1))"
