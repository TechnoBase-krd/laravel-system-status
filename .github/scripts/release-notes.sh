#!/usr/bin/env bash
# Prints Markdown release notes for the commits since the last v* tag, grouped by type.
set -euo pipefail

last=$(git describe --tags --abbrev=0 --match 'v[0-9]*' 2>/dev/null || true)
range="${last:+$last..}HEAD"
log=$(git log "$range" --no-merges --format='%h%x09%s' | sed -E 's/\t[*_`[:space:]]+/\t/')

section() {
    local title="$1" pattern="$2" lines
    lines=$(grep -E $'\t'"$pattern" <<<"$log" || true)
    if [ -n "$lines" ]; then
        echo "### $title"
        echo
        while IFS=$'\t' read -r sha subject; do
            echo "- ${subject} (${sha})"
        done <<<"$lines"
        echo
    fi
}

section "Breaking changes" '[a-z]+(\([^)]*\))?!:'
section "Features" 'feat(\([^)]*\))?:'
section "Fixes" '(fix|perf|refactor|revert)(\([^)]*\))?:'
section "Other changes" '(docs|chore|ci|style|test|build)(\([^)]*\))?:'

if [ -n "$last" ]; then
    echo "**Full changes:** ${GITHUB_SERVER_URL:-https://github.com}/${GITHUB_REPOSITORY:-TechnoBase-krd/laravel-system-status}/compare/${last}...${RELEASE_TAG:-HEAD}"
fi
