#!/usr/bin/env bash
# Fails when a migration that already exists on the base commit was modified, deleted or renamed.
# Applied migrations are never edited: databases that already ran them would silently differ from
# fresh ones. Schema changes go into a NEW migration (adding files to migrations/ is allowed).
#
# Usage: check-migrations-unchanged.sh <base-commit> [<head-commit>]
set -euo pipefail

base="${1:-}"
head="${2:-HEAD}"

if [[ -z "$base" || "$base" =~ ^0+$ ]] || ! git cat-file -e "${base}^{commit}" 2>/dev/null; then
    echo "::warning::No base commit to compare with ('${base}'); committed migrations not checked."
    exit 0
fi

# A = added (allowed). M = modified, D = deleted, R = renamed, T = type changed: forbidden.
changed="$(git diff --no-ext-diff --find-renames --name-status --diff-filter=MDRT "$base" "$head" -- migrations/)"

if [[ -n "$changed" ]]; then
    echo "::error::Committed migrations must not be changed. Create a new migration instead."
    echo "Changed migrations (M = modified, D = deleted, R = renamed, T = type changed):"
    echo "$changed"
    exit 1
fi

echo "Committed migrations unchanged."
