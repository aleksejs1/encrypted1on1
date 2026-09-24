# Unpublished drafts are encrypted with a key derived from the private key, not the password

## Problem

[GitHub issue #129](https://github.com/aleksejs1/encrypted1on1/issues/129): an unpublished draft was
encrypted with the password-derived **master key** (`Anketa.svelte`'s `saveDraft()`). An in-app password
change re-wraps the private key under a new master key but left every stored draft under the old one. On
the next load, `load()` made an unguarded `decryptBlob()` call, which threw. The whole page showed "Could
not load this anketa." and the draft was lost. The data export made the same unguarded call, so one such
draft aborted the entire export. A forgotten-password reset leaves drafts unreadable too, and there it
can't be avoided, because nobody knows the old password any more.

## Decision

1. **A draft key that doesn't depend on the password.** Drafts are now encrypted with
   `crypto/keypair.ts`'s `deriveDraftKey()`: libsodium's `crypto_kdf_derive_from_key` (keyed BLAKE2b,
   context label `e1o1drft`) over the user's X25519 private key. A password change re-wraps that same
   private key, so the draft key, and every draft, are unaffected. That includes a tab left open through
   the change, since it already holds the same private key. The draft key is exactly as secret as the
   master key was: deriving it needs the private key, which needs the master key. The server still sees
   only ciphertext. A forgotten-password reset generates a fresh keypair, so a draft from before a reset
   still can't be opened. That is inherent, and part 2 covers it. The local `sessionStorage` draft backup
   (`anketa/draftBackup.ts`) uses the same key.
2. **Graceful degradation.** `anketa/drafts.ts`'s `decryptDraft()` returns null instead of throwing.
   `Anketa.svelte`'s `load()` uses it for the draft path only; a published side's anketa-key decrypt
   still fails loudly, as before. An undecryptable draft loads the page with an empty form and a notice.
   Autosave waits until something is typed (`hasAnyAnswer()`), so opening the page never overwrites the
   stored ciphertext, and the notice clears once the user starts typing. The export records such a draft
   as `myAnswers: null, myDraftUnreadable: true` and carries on.
3. **Migration of existing drafts.** `decryptDraft()` falls back to the master key for a draft saved
   before this change and reports `legacy: true`. Two paths move legacy drafts to the draft key:
   - **On open.** `load()` re-saves the draft straight away rather than at the next edit, because the
     page's autosave `$effect` doesn't fire on load by itself (`loaded` isn't reactive). The local backup
     gets the same fallback and flag, so unsynced edits that a tab backed up under the master key aren't
     lost either. The same immediate re-save happens when a readable local backup stands in for an
     unreadable server draft. This re-save goes through the normal autosave, so opening such a page
     shows "Saving…"/"Saved." without an edit, which is accepted.
   - **Before a password change.** `AccountSettings.svelte` reads every open anketa where the user has an
     unpublished draft, re-encrypts the legacy ones under the draft key (`migrateLegacyDrafts()`), and
     saves them before it re-wraps the private key. This is safe to do ahead of the change, since the
     draft key doesn't depend on the password. A 409, 403 or 404 on a save means the anketa was archived,
     the side published or access lost in the meantime, so there's no draft of ours left to move, and
     it's skipped. Any other failure, the fetch included, stops the password change and shows the API's
     own error, or a dedicated message. The password stays
     unchanged, so the old master key still opens every draft that wasn't moved. The saves carry no
     version check. A draft autosaved in another tab during those few seconds (it would be under the draft key
     already) can be overwritten with the snapshot's older content. That is accepted: the window is short,
     and it needs a legacy draft being edited elsewhere at the very moment of the password change.
     The step costs one `/api/anketas/bulk` fetch per password change, before the server has even
     checked the current password. It can be removed once legacy drafts are gone.

   Archived anketas are skipped on both paths, because the server refuses draft saves there. An
   archived, never-published legacy draft therefore still can't be opened after a password change. The
   only places it would have shown are the read-only archived page and the export, which flags it.
   Accepted rough edges:
   - An archived anketa's unreadable draft shows no notice. Its form is read-only, so "type to replace
     it" would be wrong there, and the Archived badge already explains why the form is empty.
   - A local `sessionStorage` backup still under the master key isn't migrated by the password change,
     only on open. Only a tab that ran the old build can have one, and only its unsynced edits are at
     stake.
   - The page's "don't autosave over an unreadable draft" guard uses `hasAnyAnswer()` as its signal
     that the user has typed. That's accurate today, since no field writes a default value on its own.
     Publish isn't gated: publishing the blank form is an explicit user choice made next to the notice.
   - A tab still running the old build keeps autosaving under the master key. If the password is changed
     in another tab while it's open, whatever it saves afterwards is unreadable later. This is a
     deploy-window edge: it ends once every tab has reloaded onto the new build.
   - Not addressed here, and not new: a draft's ciphertext isn't bound to its anketa (`encryptBlob()`
     passes no associated data), and one draft key serves every anketa. A malicious server could
     therefore move a draft from one anketa to another. The master key had exactly the same property.
     Binding the anketa id as associated data is a separate change to the blob format.
   - During the deploy itself, a tab still running the old build decrypts drafts with the master key
     only. If another tab on the new build has already moved a draft to the draft key, the old tab fails
     to load that anketa, the pre-fix behavior, until it reloads onto the new build.
4. **Why the private key is a sound source.** The X25519 secret key is also the input to a keyed
   BLAKE2b PRF here. That is a deliberate, narrow reuse of key material across two primitives. The PRF
   output reveals nothing about the key, and the context label separates this use from any other. The
   textbook alternatives were a separate random draft key wrapped next to `encryptedPrivateKey`, which
   needs a new column and API field, or deriving both from a common seed, which would change how every
   existing keypair is made. Both cost far more than this narrow reuse for no practical security gain.
   Revisit if a second symmetric key ever needs to hang off the identity.

## Alternatives considered

- **Keep the master key, and re-encrypt every draft as part of the password change** (the issue's own
  suggestion, built first). It needed a new `GET /api/me/drafts`, a `drafts` field on
  `PUT /api/me/password` swapped in the same flush, and a whole-set compare-and-swap with a 409 on any
  change in between. Independent review found problems the design could narrow but not close:
  - a tab left open through the change kept autosaving under the old key;
  - a lost response left this tab holding the old key while the server held the new one;
  - every 409 retry used up one of the 5 password-change attempts allowed per hour.
  The review pointed out that the root cause was coupling drafts to a key that changes with the password.
  The maintainer chose the derived key instead, and all of that backend work was dropped.
- **Re-encrypting drafts to the *new master key* client-side, through the existing draft endpoint, before
  the password change** (the issue's suggested ordering). If the password change then failed, drafts
  would sit under a master key the user never gets. The legacy migration above does use this same
  endpoint-before-the-change shape. What makes it safe there is the target key: the draft key doesn't
  depend on the password, so a failed password change leaves nothing stranded.

## Verification

- `frontend/src/crypto/keypair.test.ts`: a known-answer vector for the draft key, reproduced
  independently with Python's `hashlib.blake2b`, so a changed KDF parameter can't silently strand every
  stored draft. Also: the key is deterministic, 32 bytes, distinct from the private key, different per
  keypair, and unchanged by a re-wrap under a new master key.
- `frontend/src/anketa/drafts.test.ts`: `decryptDraft()` with the draft key, the legacy master-key
  fallback (`legacy: true`), neither key (null), a missing master key and a corrupt blob;
  `migrateLegacyDrafts()` saving only legacy drafts, skipping a 403/404/409 and stopping on anything else; `hasAnyAnswer()`. `draftBackup.test.ts`: a
  master-key backup still opens and is flagged legacy.
- `frontend/e2e/password-change-drafts.spec.ts`, real crypto against the e2e stack, five scenarios:
  - the issue's reproduction, with the draft read back from a brand-new session after the password change;
  - a tab left open through the change that keeps autosaving, with its draft readable from a new session;
  - a legacy master-key draft (made in-page from the app's own modules) that opens, is re-saved under
    the draft key on load, and survives a subsequent password change;
  - a legacy draft nobody reopened, moved to the draft key by the password change itself;
  - an undecryptable draft: the notice appears, the stored blob is left intact past the autosave
    debounce, the export flags it, and typing replaces it and clears the notice.
