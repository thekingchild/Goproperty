#!/usr/bin/env bash
# Builds the release zip of an earlier commit for the upgrade drill (P09-06):
# a clone of this repository at <ref>, packaged by THIS checkout's release
# scripts (scripts/release/), so a packaging fix applies to both zips while
# the code, vendor/ and assets are <ref>'s own.
#
# Usage: scripts/release/build-previous.sh <ref> <version> <output-dir>
set -euo pipefail

if [ $# -ne 3 ]; then
    echo "Usage: $0 <ref> <version> <output-dir>" >&2
    exit 64
fi

ref=$1
version=$2
out=$(realpath -m "$3")
root=$(cd "$(dirname "$0")/../.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

commit=$(git -C "$root" rev-parse --verify "$ref^{commit}")
git clone --quiet --shared --no-checkout "$root" "$work/src"
git -C "$work/src" checkout --quiet --detach "$commit"
rm -rf "$work/src/scripts/release"
cp -a "$root/scripts/release" "$work/src/scripts/release"

echo "Building the previous release from $commit ($(git -C "$work/src" log -1 --format=%s))"
"$work/src/scripts/release/build-package.sh" "$version" "$out"
