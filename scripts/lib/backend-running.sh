# Sourced (not executed directly) by .githooks/pre-push and scripts/verify-pr-ready.sh
# — both need to check whether the dev stack's backend container is up before `exec`ing
# into it. Previously copy-pasted three times across the two scripts; a code-review
# round pointed out the inconsistency with this same change already extracting
# scripts/lib/detect-upstream-ref.sh and backend/bin/mutation.sh for the identical
# drift-risk reason.
#
# Defines backend_running() rather than a plain variable, since (unlike UPSTREAM_REF)
# this needs re-checking at each call site, not computed once up front.

backend_running() {
    docker compose -f docker-compose.dev.yml ps -q backend 2>/dev/null | grep -q .
}
