# Encryption methodology

This document describes the cryptographic design: what is derived from what, what algorithm does which job, and — most importantly — what the server can and cannot see. For the user-facing sequence of screens this produces, see [user-flow.md](user-flow.md). For where this code lives, see [architecture.md](architecture.md).

All cryptography happens in the browser, via [libsodium](https://doc.libsodium.org/) (`libsodium-wrappers-sumo`) and, for one step, the browser's native WebCrypto API. The server never receives a password and never receives an unencrypted key.

## Key derivation

A user's password is the root of everything. It is never sent to the server and never stored anywhere, in any form.

```
password + salt(email)  →  argon2id  →  intermediate key
                                              │
                                     HKDF-SHA256 (two branches)
                                              │
                              ┌───────────────┴───────────────┐
                              │                                │
                          "auth" info                   "master-key" info
                              │                                │
                          auth key                       master key
                    (→ sent to server)              (never leaves the browser)
```

1. **Salt.** The argon2id salt is not random and not stored anywhere — it's derived deterministically from the user's normalized (trimmed, lowercased) email via BLAKE2b (`crypto_generichash`). This avoids a separate per-user salt column, an extra round-trip to fetch it before a password can even be typed, and — importantly — avoids turning "does this email have a salt yet" into an account-enumeration side channel.
2. **argon2id.** The password and that salt go through `crypto_pwhash` with the Argon2id algorithm, libsodium's "interactive" cost parameters. This is the one intentionally slow, memory-hard step — its entire purpose is making offline password guessing expensive even if the server's database leaks.
3. **HKDF-SHA256 split.** The 32-byte argon2id output is fed into HKDF-SHA256 (via the browser's native WebCrypto — neither the standard nor the "sumo" build of libsodium-wrappers expose real HKDF extract/expand, only byte-length constants, so this one step deliberately uses a different library) twice, with two different `info` strings, producing two **independent** 32-byte keys:
   - **auth key** — proves to the server "this is the right password," and nothing else.
   - **master key** — the only key that can unwrap the user's private key. Never transmitted.

Splitting these matters: if they were the same key (or one derived from the other in a reversible way), a server compromise that reveals the auth key would also compromise the master key. Because they're independent HKDF branches of the same intermediate secret, knowing the auth key gives no computational shortcut to the master key.

## What the server actually stores, per user

| Field | What it is | Can the server derive your password or master key from it? |
|---|---|---|
| `authHash` | The auth key, stored **verbatim** (not re-hashed) | No — it's already the output of a slow KDF chain; storing it as-is is the point of the split above |
| `publicKey` | X25519 public key | No — public by definition |
| `encryptedPrivateKey` | The X25519 private key, wrapped with the master key | No — needs the master key, which the server never has |

Login (`AuthController::login()`) is a single constant-time comparison: the browser derives the auth key again from the entered password and sends it; the server compares it against the stored `authHash` with `hash_equals()`. There is no server-side re-hash, no bcrypt/argon2 verification step on the server at all — the expensive part already happened in the browser. A fixed-length dummy hash is compared against even when the email doesn't exist, so a "no such account" response takes the same time as a "wrong password" one.

## Identity keys

On account activation, the browser generates a fresh X25519 keypair (`crypto_box_keypair`) for that user. The private key is immediately wrapped — encrypted with the master key, using XChaCha20-Poly1305 (`crypto_aead_xchacha20poly1305_ietf`, an authenticated cipher: decrypting with the wrong key throws rather than silently returning garbage) — and only the wrapped form, plus the public key, is sent to the server. This pair is what lets two people who've never directly exchanged anything establish a shared secret for a specific anketa (below).

A page refresh doesn't require re-entering the password: the master key lives in `sessionStorage` (tab-scoped — gone when the tab closes, present across a refresh) for the rest of that session, and the private key is cheaply re-unwrapped from it plus the server's `encryptedPrivateKey` on demand.

The only other time a fresh keypair gets generated is a forgotten-password reset — the old master key that could unwrap the old private key is gone along with the forgotten password, so there's nothing to re-wrap; see [user-flow.md](user-flow.md#getting-an-account) for what that means for existing anketas. Changing a *remembered* password (Account Settings) is the opposite case: the same private key is re-wrapped under a new master key, no fresh keypair involved.

## Per-anketa keys

Each anketa (a single 1:1 meeting cycle between one manager and one employee) gets its own symmetric key, generated fresh in the browser of whoever creates it:

1. A random 32-byte XChaCha20-Poly1305 key is generated for the anketa.
2. It's **sealed** — `crypto_box_seal`, libsodium's anonymous public-key encryption — to each participant's X25519 public key, once per side. "Anonymous" means no sender keypair is needed or used; anyone can seal a message to a public key, but only the holder of the matching private key can open it. This is what lets the creator hand the same key to a counterpart they may never interact with directly, through the server, without the server ever holding the unsealed key.
3. Both sealed copies (`employeeSealedKey`, `managerSealedKey`) go to the server. Each participant unseals *their own* copy locally with their own private key when they load the anketa.

Everything specific to that anketa — both participants' question answers, the shared comment thread, the outcomes list, goal progress checkpoints — is encrypted with this one key.

### Envelope format

Every encrypted field uses the same small versioned envelope, so a future schema change has somewhere to signal itself without guessing at ciphertext contents:

```
plaintext  = { "schemaVersion": 1, "data": <the actual answers/comments/outcomes/...> }
ciphertext = base64( nonce ‖ XChaCha20-Poly1305(plaintext) )
```

One random nonce per encryption; it isn't secret and travels alongside the ciphertext.

## The one deliberate plaintext exception

A goal's **title, description, status, and target date** are stored unencrypted on the server, in a real database table with real columns — not inside an encrypted blob. This is a narrow, explicit, product-level exception, made so goals can be listed, filtered, and carried forward from one anketa cycle to the next by the server itself, rather than requiring the client to fetch and decrypt every historical anketa just to know which goals are still open.

A goal's **progress checkpoints** — the actual updates on how it's going — stay fully encrypted like everything else. Nothing else in the application gets this exception; it exists because it was explicitly discussed and decided as a product trade-off, not because encrypting it was hard.

## Threat model — what a full server compromise reveals

Assume the worst case: an attacker has read access to the entire database, every stored file, and the server's memory at some instant.

**Visible:**
- Email addresses, who is paired with whom (employee/manager relationships), meeting dates, periodicity, archived/missed/overdue status.
- Goal titles, descriptions, statuses, and target dates (the one exception above).
- That an anketa exists, was published, has N comments — metadata, not content.
- Which admin invited whom, account creation dates, blocked/admin flags.

**Not visible, under any circumstance short of a stolen password:**
- Anketa question answers, from either side, published or draft.
- Comment text.
- Outcome items' text.
- Goal progress checkpoint text.
- Any user's password, or anything that lets one be recovered.
- Any user's master key or private key (only wrapped/sealed forms are ever stored).

**A caveat worth stating plainly:** an attacker who compromises the server *and* can intercept or tamper with a specific user's live session (not just read the database at rest) could, in principle, serve that one user malicious client-side code and capture their password as they type it — no purely server-side encryption scheme can defend against a compromised or dishonest client, and this app doesn't claim to. The guarantee is about what a passive database/backup compromise reveals, not about defending against an actively malicious server operator serving different code to a targeted user. A strict Content-Security-Policy plus Subresource Integrity on the built JS/CSS (see [architecture.md](architecture.md)) narrows this a little — a compromised build pipeline or tampered static assets get caught by the browser refusing to execute them — but neither defends against a live server actively colluding to serve a specific victim different, self-consistent, matching-hash content; that's the same fundamental limit stated above, not something CSP/SRI can close.

**A second real, non-cryptographic caveat:** an admin can *block* or *unblock* accounts and *promote/revoke* admin status (`AdminController`), and can generate account-activation links — none of that requires or grants access to anketa content, since none of it involves any key material. It's an authorization boundary, not a cryptographic one, and is enforced entirely server-side.
