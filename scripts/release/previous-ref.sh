#!/usr/bin/env bash
# Prints the commit the upgrade drill (P09-06, INSTALLER.md § 12) treats as
# the previous release of <ref> (default HEAD), first match wins:
#
#   1. $PREVIOUS_REF, when set (a tag, branch or commit; the workflow's input)
#   2. the newest v* tag (version order) that is an ancestor of <ref> and
#      does not point at it: the release before this one
#   3. $BASE_REF, when set (a pull request's base commit: what main runs now)
#   4. <ref>'s first parent (on main: the state before the last merge)
#
# Needs the full history and tags (actions/checkout with fetch-depth: 0).
# Usage: scripts/release/previous-ref.sh [<ref>]
set -euo pipefail

ref=${1:-HEAD}
head=$(git rev-parse --verify "$ref^{commit}")

resolve() { git rev-parse --verify --quiet "$1^{commit}"; }

if [ -n "${PREVIOUS_REF:-}" ]; then
    resolve "$PREVIOUS_REF" || { echo "PREVIOUS_REF '$PREVIOUS_REF' is not a commit here" >&2; exit 1; }
    exit 0
fi

while IFS= read -r tag; do
    [ -n "$tag" ] || continue
    commit=$(resolve "$tag") || continue
    if [ "$commit" != "$head" ]; then
        echo "$commit"
        exit 0
    fi
done < <(git tag --list 'v[0-9]*' --merged "$head" --sort=-v:refname)

if [ -n "${BASE_REF:-}" ] && commit=$(resolve "$BASE_REF") && [ "$commit" != "$head" ]; then
    echo "$commit"
    exit 0
fi

resolve "$head^1" || { echo "$ref has no parent commit to upgrade from" >&2; exit 1; }
