# User flow

This describes the app from the perspective of the people using it — what each step feels like and why it works the way it does. For the cryptographic mechanism behind each step, see [encryption.md](encryption.md). For *why* the 1:1 cycle is structured this way and what each question is actually for, see [methodology.md](methodology.md).

## Getting an account

There's no public sign-up. Two ways to get an account:

- **The very first account on a fresh instance** is created from the server's own command line: `bin/console app:create-activation-link <email> --admin`. This is deliberate — it's the one bootstrap step that has to happen outside the app itself, since there's no admin yet to invite anyone.
- **Every account after that** comes from an invite: any authenticated user (if the instance is configured with `REGISTRATION_MODE=invite`) or only an admin (`REGISTRATION_MODE=admin_only`) enters an email address, and the app sends a one-time activation link to it.

Either way, activation works the same: the link is single-use and expires after a fixed window. Opening it prompts for a password.

**This password is not "an account password" in the usual sense — choosing it is the moment your encryption key is created.** The app says so directly on the activation screen, because it's the single most consequential thing a new user does: that password, run through the derivation described in [encryption.md](encryption.md), is what everything else depends on.

> **There is no password reset.** If a user forgets their password, there is no "reset link" that can restore access to that account's existing anketas — the server has never had anything that could decrypt them, so it has nothing to hand back. (An admin can still deactivate the old account and issue a fresh invite under the same email if needed to start over; the old encrypted history simply can't be recovered into it.)

## Logging in

Email and password, same as anywhere. Behind that simple form: the password gets run through the same derivation again (never sent as-is), and the resulting key is checked against the server's copy in constant time. The UI shows an explicit "unlocking your data" state during this, since the derivation step is deliberately slow (see *why* in encryption.md) and can take a perceptible moment.

Once logged in, the session lives in two places with two different lifetimes:
- A regular httpOnly session cookie (server-side, like any web app) keeps you *authenticated*.
- The unwrapped encryption key lives only in that browser tab's memory/`sessionStorage` — it survives a page refresh, but closing the tab clears it. Opening the app again in a new tab means logging in again. This is a deliberate trade-off explained on the login screen itself, not a hidden limitation: convenience (no re-typing on every refresh) without persisting key material anywhere durable.

## Starting a new 1:1 cycle ("anketa")

Either the manager or the employee can start one:
1. Pick the counterpart by typing their email (a live-filtered list of existing accounts).
2. Pick which role you're filling in this anketa — employee or manager. (The app doesn't enforce that the two participants pick complementary roles; it trusts them to coordinate this themselves, the same way it trusts them not to share their own password with each other.)
3. Set a meeting date.
4. The first time a given pair meets, they also set how often they'll repeat this (weekly / every two weeks / monthly). Every anketa after the first for that same pair inherits the periodicity automatically — the form explains this rather than just silently hiding the field.

Creating the anketa is also the moment its encryption key is generated and handed to both participants (sealed to each one's public key, as described in encryption.md) — from this point on, both sides can decrypt everything in it, and no one else can.

## Filling it out

Each side answers their own set of questions privately — an employee side (mood, workload, feelings, growth, friction, achievements, things to discuss) and a manager side (how the period went, feedback, support, the employee's achievements worth recognizing, things to discuss). See [methodology.md](methodology.md#what-each-question-is-asking-and-why) for what each one is actually asking. Answers autosave as a draft every second of inactivity, encrypted with your own session key even before publishing.

**Publishing is one-way.** Once you publish your side, it's visible to your counterpart and can no longer be edited. There's no draft-recall after that point — the app treats "published" as a real commitment, not a checkpoint you can walk back.

Each side can only see the other's answers once *that* side has published theirs too — until then, it just shows "not published yet."

## Comments, outcomes, and goals

These three live alongside the questions and work throughout the anketa's lifetime, not just before publishing:

- **Comments** can be left on individual answers, outcome items, goals, or goal checkpoints — a small collapsible thread under each one, both sides can post.
- **Outcomes** are a shared checklist ("meeting outcomes") either side can add items to — but only the person who added an item can check it off or edit it; the other side can only comment on it. This ownership rule is stated directly in the UI, not left implicit. See [methodology.md](methodology.md#outcomes-vs-goals--tactical-vs-strategic) for why outcomes and goals are two different, deliberately separate lists.
- **Goals** are the one part of the system with real plaintext-server involvement (see encryption.md's "one deliberate plaintext exception") — a goal has a title, description, target date, and status (in progress / achieved / cancelled), editable only by whoever created it, plus a series of encrypted progress checkpoints anyone can add. A small info toggle on the goals section explains exactly which fields are and aren't encrypted, right where a user would want to know.

## Wrapping up a cycle

When the meeting date passes:
- If it hasn't happened yet, either side sees an "overdue" state with two options: **reschedule** (push the date, same anketa) or **cancel as missed** (archives this cycle without a next one being auto-created).
- Otherwise, either participant can **archive** it once both sides feel it's done. Archiving does two things: it closes this cycle, and — unless "don't create the next meeting" is checked — it immediately creates the *next* anketa for the same pair, on the same periodicity, with a fresh encryption key generated and sealed the same way as the very first one. Any unchecked items from the outcomes list carry forward into the new anketa automatically, still encrypted, still requiring both participants' keys to read. Open goals carry forward too (their plaintext row is copied so status/history persists across cycles).
- If either participant has been blocked by an admin in the meantime, auto-recreation simply doesn't happen — no error, it just stops.

The day before a scheduled meeting, both participants get a reminder email; if one side hasn't published their answers yet, they get an extra nudge specifically about that.

## Reports

Either side can generate a report across a date range — every achievement and growth-log entry from that period, and every goal touched in it with its full checkpoint history, decrypted and assembled entirely in the browser from anketas already accessible through the normal per-anketa endpoints. The server never sees this aggregation; it just serves the same encrypted anketas it always would.

## Admin

Admins get one extra screen: the full user list, with the ability to invite, block/unblock (a reversible login gate — doesn't touch or expose any of that user's encrypted data), and grant/revoke admin status. None of this requires or grants access to anketa content — it's an authorization capability, entirely separate from the encryption model (see encryption.md's threat-model section for why that separation matters).

## Language

The interface (English, Russian, Latvian, Spanish) is a pure client-side preference, switchable anytime, independent of what language a given account's notification emails go out in — those follow whatever the account holder last set explicitly, since the two can reasonably differ (e.g. someone reading the UI in one language while a colleague, or their own inbox habits, expect emails in another).
