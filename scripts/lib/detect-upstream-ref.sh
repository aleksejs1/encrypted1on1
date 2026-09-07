# Sourced (not executed directly) by .githooks/pre-push and scripts/verify-pr-ready.sh
# — both need the same "what ref do we diff against" fallback (the branch's own tracked
# upstream, else origin/main, else nothing to diff against at all). Previously
# duplicated near-verbatim in both scripts; a code-review round flagged the drift risk
# — the same reasoning that already justified consolidating the Infection invocation
# itself into backend/bin/mutation.sh in this same change.
#
# Sets $UPSTREAM_REF (empty string if neither a tracked upstream nor origin/main
# exists — e.g. the very first push of a fresh clone). Callers build their own range
# expression from it (pre-push wants `$UPSTREAM_REF...HEAD`; verify-pr-ready.sh wants
# `git merge-base "$UPSTREAM_REF" HEAD` diffed against the working tree) since that part
# differs by caller.

if git rev-parse --abbrev-ref --symbolic-full-name '@{u}' >/dev/null 2>&1; then
    UPSTREAM_REF='@{u}'
elif git show-ref --verify --quiet refs/remotes/origin/main; then
    UPSTREAM_REF='origin/main'
else
    UPSTREAM_REF=''
fi
