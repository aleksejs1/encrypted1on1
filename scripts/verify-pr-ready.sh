#!/bin/sh
set -u

# One-command pre-PR gate for agents and humans (CLAUDE.md's own working-style section,
# the e1o1-agent-playbooks skill's pre-review checklist) — runs `make lint`/`make test`
# (schema-validate/stan/cs/md, ESLint/svelte-check/knip/format, jscpd, doc-links,
# PHPUnit, Vitest) plus a changed-file-scoped mutation-testing pass, informational like
# the CI `mutation` job itself (see docs/architecture.md's "Testing and CI" section for
# why that job doesn't have a --min-msi/--min-covered-msi threshold yet). Whole-*file*-
# scoped, not line-scoped like CI's own `--git-diff-lines` — Infection's positional-path
# arguments mutate every mutatable line in a changed file, not just the changed lines
# within it — so a small edit to a large file costs more here than the equivalent CI
# run. This doesn't replace CI, and deliberately doesn't try to reproduce every one of
# its gates: `composer audit`/`npm audit --audit-level=high` (dependency CVEs),
# `test-coverage`'s enforced coverage threshold, e2e, and backup-restore all stay
# CI-only — a review round pointed out that this script's own "PASS" message used to
# imply full CI parity it doesn't have; the message below says what actually ran.
#
# Needs the same prerequisites `make lint`/`make test` already do: `make up` (dev stack
# running) for backend checks, `cd frontend && npm install` for frontend checks,
# `npm install` at the repo root for duplication.
#
# Usage: ./scripts/verify-pr-ready.sh   (or: make verify-pr-ready)

REPO_ROOT=$(git rev-parse --show-toplevel) || exit 1
cd "$REPO_ROOT" || exit 1

# Shared with .githooks/pre-push (see that file's own lib comment for why this used to
# be duplicated). Resolved to the merge-base explicitly here — git diff has no
# working-tree-vs-merge-base triple-dot form the way `A...B` does between two commits —
# and diffed against the working tree, not just the committed range: this runs "before
# opening a PR", which includes work still uncommitted, unlike pre-push's own
# post-commit context. Using the merge-base rather than the upstream ref directly
# matters the same way pre-push's own `...HEAD` does: without it, commits that landed
# on origin/main *after* this branch forked would show up as "changed" here too,
# inflating the mutation-testing scope with files nobody actually touched locally.
. scripts/lib/detect-upstream-ref.sh
. scripts/lib/backend-running.sh

if [ -n "$UPSTREAM_REF" ]; then
    BASE=$(git merge-base "$UPSTREAM_REF" HEAD) || {
        echo "verify-pr-ready: could not find a merge-base between $UPSTREAM_REF and HEAD (diverged/rebased history, or a shallow clone?) — aborting rather than silently checking nothing." >&2
        exit 1
    }
    # --diff-filter=d excludes deletions — a deleted file is not a meaningful mutation
    # target (there's nothing left to mutate), and would otherwise reach Infection as a
    # positional path argument pointing at a file that no longer exists on disk.
    # Untracked files (git diff never lists these — a real gap, since this script's
    # whole point is covering work still uncommitted) are added in separately.
    CHANGED=$(git diff --name-only --diff-filter=d "$BASE"; git ls-files --others --exclude-standard)
else
    # No upstream and no origin/main (e.g. very first push of a fresh clone) — fall
    # back to treating everything as touched rather than silently checking nothing.
    # Unions in untracked files too, same as the branch above — a review round caught
    # this branch originally omitting them, contradicting this script's own stated
    # point of covering work still uncommitted.
    CHANGED=$(git ls-files; git ls-files --others --exclude-standard)
fi

STATUS=0

if ! backend_running; then
    echo "WARNING — the dev stack isn't running ('make up'); backend lint/test/mutation steps below will fail with raw docker errors instead of a clean skip." >&2
fi

echo "==> make lint"
make lint || STATUS=1

echo "==> make test"
make test || STATUS=1

BACKEND_SRC_CHANGED=$(printf '%s\n' "$CHANGED" | grep '^backend/src/' | sed 's|^backend/||')
if [ -n "$BACKEND_SRC_CHANGED" ]; then
    echo "==> Mutation testing (scoped to changed backend/src files, informational — see docs/architecture.md)"
    if backend_running; then
        # Positional path arguments, not --filter — deprecated since Infection 0.34
        # in favor of exactly this (confirmed via `vendor/bin/infection --help`).
        # bin/mutation.sh (shared with composer.json's "mutation" script and CI's
        # `mutation` job) supplies the threads/memory_limit flags — kept in one place.
        #
        # Passed as real argv to `docker compose exec`, not embedded in a string for an
        # inner `sh -c` to re-parse — a code-review round flagged the previous version
        # (unquoted interpolation into a nested shell string) as a real word-splitting/
        # glob-expansion risk. Splitting BACKEND_SRC_CHANGED into positional parameters
        # still needs one unquoted expansion, but IFS is narrowed to newline-only first
        # (rather than left at its default space/tab/newline) and `set -f` disables
        # globbing — a second review round caught that the default IFS would otherwise
        # also split on a space *within* a single changed path, corrupting it into two
        # bogus arguments instead of one.
        OLD_IFS=$IFS
        IFS='
'
        set -f
        set -- $BACKEND_SRC_CHANGED
        set +f
        IFS=$OLD_IFS
        # Checked, unlike an earlier version of this script — Infection itself exits 0
        # when mutants merely escape (none of this repo's three call sites set
        # --min-msi, so that's expected and this step stays informational as intended),
        # but a non-zero exit here means the tool itself didn't run cleanly (a fatal
        # PHP error, the test-db-reset step failing, the exec transport itself failing)
        # — a review round pointed out that going unchecked meant a genuine crash could
        # still print "PASS: ready to open a PR" right after it.
        docker compose -f docker-compose.dev.yml exec -T backend bin/mutation.sh -- "$@" || STATUS=1
    else
        echo "    WARNING — backend/src changed but the dev stack isn't running ('make up'); skipping. CI's mutation job still covers this on a real PR." >&2
    fi
else
    echo "==> Mutation testing: no backend/src changes, skipping"
fi

if [ "$STATUS" -eq 0 ]; then
    echo "PASS: lint/test/mutation clean — CI still separately runs dependency audits, enforced coverage thresholds, e2e, and backup-restore verification."
else
    echo "FAIL: fix the above before opening a PR" >&2
fi
exit "$STATUS"
