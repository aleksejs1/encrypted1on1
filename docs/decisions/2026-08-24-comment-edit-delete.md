# Let users edit/delete their own comments, everywhere (not just non-archived anketas)

## Problem

Anketa field/outcome/goal/checkpoint comments (`frontend/src/anketa/comments.ts`)
supported add only — a typo or a comment on the wrong field had no fix short of
living with it forever.

## Decision

Added `editComment`/`deleteComment` (pure functions, same shape as `addComment`)
and wired them into `CommentThread.svelte` (inline edit form; a two-step
"Delete" → "Confirm delete" for delete, matching this codebase's lack of any
shared confirmation-modal component rather than introducing one for a single,
low-stakes case) and `Anketa.svelte`'s existing `updateComments`/`updateField`
reapply-on-conflict plumbing — no new endpoint, no new blob shape, no schema
change. Both actions are allowed on archived anketas too, matching `addComment`'s
existing (unguarded) behavior — `saveComments`/`saveOutcomes` are the two
`AnketaController` blob endpoints that never gained an `isArchived()` check,
unlike goals/checkpoints/reschedule.

"Only your own comment" is enforced by matching `authorId` inside
`editComment`/`deleteComment` themselves, client-side only: comments live in
one shared encrypted blob per anketa and the server never inspects blob
contents (true for `addComment` since it was built), so there is no server-side
place to check authorship. This is the same trust model `addComment` already
had — nothing new is exposed by extending it to edit/delete.

`editComment`/`deleteComment` *throw* (rather than silently returning the list
unchanged) when the target comment isn't found — `updateField` always
re-fetches the blob fresh before applying the mutation, so a miss means the
comment was deleted elsewhere (another tab, a conflict retry) between opening
the edit/delete UI and submitting it. An initial version returned the
unchanged list on a miss; code review caught that the caller had no way to
tell "nothing to do" apart from "applied successfully," so `CommentThread.svelte`
closed the edit form / delete dialog as if the edit or delete had gone
through while silently dropping it. Throwing routes it through the same
`onEdit`/`onDelete` catch blocks (`commentThread.error`) already used for a
failed save — and since `blobSync.ts`'s `apply()` call happens before
`encryptBlob`/`save`, no wasted PUT is sent either.

`CommentThread.svelte`'s `editingId`/`confirmingDeleteId` are single,
un-scoped `$state` for the whole thread (one edit/delete active at a time),
which code review flagged across two rounds: starting a second own comment's
edit/delete while a first was already open would silently reassign that
shared state, discarding whatever the first had pending with no warning. The
first fix only disabled other comments' Edit/Delete-opening buttons while a
request was actually *in flight* (`editBusy`/`deleteBusy`); a second review
round caught that this left the far more common *open-but-unsubmitted*
window unguarded — e.g. open Edit on comment A, type a correction, don't
save yet, then open Edit on comment B: nothing was in flight, so B's button
was still enabled and opening it silently wiped out A's unsaved text. Fixed
by gating on `editingId !== null || confirmingDeleteId !== null` instead
(`anotherActionOpen`) — since `editBusy`/`deleteBusy` are only ever true
while `editingId`/`confirmingDeleteId` already point at the comment being
saved, this single check covers both the open-and-typing window and the
in-flight-saving window, and is simpler than tracking both.

## Alternatives considered

Restricting edit/delete to non-archived anketas only (the user's first
instinct) was rejected: it would be new, inconsistent behavior relative to
`addComment`, which has always worked on archived anketas, for no real benefit
— the underlying endpoint and blob have no concept of "read-only because
archived" today.

## Verification

Real round trip against the isolated e2e stack (`make e2e-up`, port 8001,
real activation via `app:create-activation-link`, real argon2id/HKDF/X25519/
XChaCha20-Poly1305 crypto mirroring `frontend/src/crypto/*.ts` exactly, no
mocks) — a throwaway Node script: two real accounts, a real anketa, employee
adds/edits/deletes a comment through the real `PUT /api/anketas/{id}/comments`
blob endpoint, manager independently unseals the anketaKey and decrypts the
result at each step, confirmed the *server-stored* blob is genuinely ciphertext
(not the plaintext comment text), confirmed `commentsVersion` increments
correctly, and confirmed the existing optimistic-locking 409-on-stale-version
behavior is unchanged. Script and its two throwaway accounts were deleted
afterward. `editComment`/`deleteComment` throwing on a mismatched
`authorId`/unknown id (the fix noted above, made after this round of
verification) is covered by real, non-mocked Vitest cases in
`comments.test.ts` instead — plain array logic with no crypto of its own, so
a second real round trip wasn't warranted for it.

Permanent coverage was also added to `frontend/e2e/dual-actor-anketa.spec.ts`
(the one existing test already covering `addComment` across two real,
independent browser sessions) rather than a new spec — it already pays for
the expensive setup (two accounts, an anketa, both sides published) this
would otherwise have to repeat. The manager edits their own comment through
the real Edit/Save UI and posts-then-deletes a second one through the real
two-step Delete/Confirm-delete UI (real typing/clicking, real WASM
argon2id/X25519/XChaCha20-Poly1305 in an actual Chromium tab — this is the
"a real UI interaction" layer the `e1o1-verify-with-real-crypto` skill calls
out, not just the Node-script layer used above), and the employee's separate
session, after a reload, is asserted to see the edited text (not the
pre-edit text, not the deleted comment) and to have no Edit/Delete buttons
at all on a comment they don't own. Confirmed passing (`make e2e`, all 3
specs). Full check sweep also run and green: `composer stan`/`cs`/`md`/`test`
(213 backend tests, isolated stack), `npm run check`/`format`/`knip`,
`jscpd`, `check-doc-links.mjs`.

`frontend`'s Vitest suite (89 tests) and `svelte-check` both pass.
