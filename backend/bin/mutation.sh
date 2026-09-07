#!/bin/sh
set -eu

# Shared Infection invocation for backend/composer.json's "mutation" script,
# ../../scripts/verify-pr-ready.sh, and ../../.github/workflows/ci.yml's "mutation" job
# — the threads/memory_limit flags previously lived duplicated across two of those, a
# real drift risk a code-review round caught (a future memory_limit bump in one place
# silently not applying to the others). Run from backend/ (composer scripts and the CI
# job both already cd there). Any arguments given are forwarded to Infection as-is —
# e.g. a set of positional file paths (verify-pr-ready.sh's own local, changed-file-
# scoped run) or --git-diff-lines --git-diff-base=... (CI's PR-diff-scoped run). No
# arguments at all means Infection's own default: every configured source file
# (composer mutation / make mutation-backend's full local run).

composer test-db-reset
vendor/bin/infection --threads=4 --no-progress --initial-tests-php-options="-d memory_limit=512M" "$@"
