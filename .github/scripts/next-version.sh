#!/usr/bin/env bash
# Prints the next version tag (vX.Y.Z) for the commits since the last v* tag, or nothing when
# those commits need no release. Usage: next-version.sh [auto|patch|minor|major]
#
# auto reads Conventional Commit subjects:
#   feat!: / fix!: / any "type!:" or a "BREAKING CHANGE" footer  -> major
#   feat:                                                         -> minor
#   fix: / perf: / refactor: / revert:                            -> patch
#   docs: / chore: / ci: / style: / test: only                    -> no release
set -euo pipefail

bump="${1:-auto}"
last=$(git describe --tags --abbrev=0 --match 'v[0-9]*' 2>/dev/null || true)

if [ -z "$last" ]; then
    base="0.0.0"
    range="HEAD"
else
    base="${last#v}"
    range="$last..HEAD"
fi

if [ "$bump" = "auto" ]; then
    # Tolerate decorations such as **feat: …** at the start of a subject.
    subjects=$(git log "$range" --format='%s' | sed -E 's/^[*_`[:space:]]+//')
    bodies=$(git log "$range" --format='%b')

    if [ -z "$subjects" ]; then
        exit 0
    fi

    if grep -qE '^[a-z]+(\([^)]*\))?!:' <<<"$subjects" || grep -q 'BREAKING CHANGE' <<<"$bodies"; then
        bump=major
    elif grep -qE '^feat(\([^)]*\))?:' <<<"$subjects"; then
        bump=minor
    elif grep -qE '^(fix|perf|refactor|revert)(\([^)]*\))?:' <<<"$subjects"; then
        bump=patch
    else
        exit 0
    fi
fi

IFS=. read -r major minor patch <<<"$base"

case "$bump" in
    major) major=$((major + 1)); minor=0; patch=0 ;;
    minor) minor=$((minor + 1)); patch=0 ;;
    patch) patch=$((patch + 1)) ;;
    *) echo "Unknown bump: $bump" >&2; exit 1 ;;
esac

echo "v$major.$minor.$patch"
