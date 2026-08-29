# User flow

This describes the app from the perspective of the people using it — what each step feels like and why it works the way it does. For the cryptographic mechanism behind each step, see [encryption.md](encryption.md). For *why* the 1:1 cycle is structured this way and what each question is actually for, see [methodology.md](methodology.md).

## Getting an account

- **The very first account on a fresh instance** is created from the server's own command line: `bin/console app:create-activation-link <email> --admin`. This is deliberate — it's the one bootstrap step that has to happen outside the app itself, since there's no admin yet to invite anyone.
- **Every account after that** depends on how the instance is configured (its registration mode, see [deployment.md](deployment.md#core-every-production-deployment)): an invite from any authenticated user (`invite`), an invite from an admin only (`admin_only`), or — if the instance opts into it (`domain`) — open self-registration restricted to a specific email domain, gated by double opt-in: submitting your email is the first opt-in, clicking the link that gets emailed to you is the second. By default, self-registration is off (`invite`), so most instances still work the invite-only way described above.

Either way, activation works the same: the link is single-use and expires after a fixed window. Opening it prompts for a password.

**This password is not "an account password" in the usual sense — choosing it is the moment your encryption key is created.** The app says so directly on the activation screen, because it's the single most consequential thing a new user does: that password, run through the derivation described in [encryption.md](encryption.md), is what everything else depends on.

> **Forgetting your password is recoverable, but not free.** "Forgot password?" on the login screen emails a reset link — but since the server has never had anything that could decrypt your old anketas, resetting genuinely can't hand that access back either. Instead, completing the reset generates a **fresh** encryption keypair, so the account itself is usable again immediately, but every anketa sealed under the old keypair becomes unreadable until each counterpart re-shares it: the app detects this automatically, shows a counterpart a "re-share access" banner on their anketa list, and one click re-seals the affected anketas' keys to the new keypair — no plaintext ever crosses the server to make that happen. New anketas and anything a counterpart already re-shared are unaffected either way. (If you still remember your current password and just want to change it, do that from Account Settings instead — see below — since it re-wraps your *existing* key rather than generating a new one, so nothing needs re-sharing at all.)

## Logging in

Email and password, same as anywhere. Behind that simple form: the password gets run through the same derivation again (never sent as-is), and the resulting key is checked against the server's copy in constant time. The UI shows an explicit "unlocking your data" state during this, since the derivation step is deliberately slow (see *why* in encryption.md) and can take a perceptible moment.

Once logged in, the session lives in two places with two different lifetimes:
- A regular httpOnly session cookie (server-side, like any web app) keeps you *authenticated*.
- The unwrapped encryption key lives only in that browser tab's memory/`sessionStorage` — it survives a page refresh, but closing the tab clears it. This is a deliberate trade-off explained on the login screen itself, not a hidden limitation: convenience (no re-typing on every refresh) without persisting key material anywhere durable. Opening the app in a *new* tab is still authenticated (the session cookie is shared across tabs), but that tab has no key of its own yet — `App.svelte` detects this (`authState.unlockStatus`, checked via `checkUnlocked()` in `auth.svelte.ts`) and shows `UnlockTab.svelte`, a lightweight password-only re-entry screen, instead of rendering pages that would otherwise silently fail to decrypt anything.

## Account settings

A few self-service account controls, reachable from a link in the header once logged in:

- **Change password.** Unlike the forgot-password flow above, this is for someone who still remembers their current password and just wants a new one — it re-wraps the *same* existing keypair rather than generating a fresh one, so nothing becomes unreadable and no counterpart ever needs to re-share anything. Requires entering the current password first, the same way any sensitive account action does.
- **Meeting reminder emails** can be turned off per-account — the day-before reminder and the "you haven't filled this out yet" nudge specifically. The email announcing a *new* anketa is not covered by this toggle and always goes out, since it's the one notification that's actually load-bearing (it's how the other participant learns a cycle started at all).
- **Export your data** downloads everything currently visible to you — your own answers (including an unpublished draft, if you have one), any counterpart's published answers, comments, outcomes, and goals — as a single JSON file, decrypted entirely in the browser. Nothing new is computed on the server for this; it's the same data the app already lets you see, fetched in one batch and gathered into a file instead of being displayed.
- **Delete your account** is permanent and, unlike the two actions above, does affect other people — but narrowly: your own profile and encryption keypair are removed, and any of your own still-unpublished drafts are lost, but every anketa you're part of stays exactly as your counterparts already see it. Nothing is cascaded or torn down on their side.

## Your anketa list

Once logged in, this is the home screen: every counterpart you have anketas with, sorted by next meeting date (soonest first) — there's no separate "manager dashboard"; someone with several direct reports just sees a longer list, sorted the same way as everyone else's.

A toggle switches between two views:
- **By date** — a flat, chronological list across every counterpart.
- **By person** — grouped by counterpart, each group showing that pair's full archived history plus two small trend sparklines (mood, workload) built from your own past answers with them, so a shift over many meetings is visible at a glance instead of buried inside individual anketas. Both are computed entirely in the browser from anketas you can already see — nothing new happens on the server for this.

If any of your anketas were sealed under a counterpart's now-outdated encryption key (see "forgetting your password" above), a banner appears at the top with a one-click "re-share access" action. An overdue anketa — meeting date passed, not yet archived — gets a visible badge here regardless of which view you're in.

## Starting a new 1:1 cycle ("anketa")

Either the manager or the employee can start one:
1. Pick the counterpart by typing their email (a live-filtered list of existing accounts) — people you've already had anketas with show up first, so you're not scrolling the full company list every time.
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

Either side can generate a report across a date range — every achievement and growth-log entry from that period, and every goal touched in it with its full checkpoint history, decrypted and assembled entirely in the browser from anketas already accessible to you. The server never sees this aggregation; it just serves the same encrypted anketas it always would, fetched in one batch rather than one request per anketa.

## Admin

Admins get one extra screen: the full user list, with the ability to invite, block/unblock (a reversible login gate — doesn't touch or expose any of that user's encrypted data), and grant/revoke admin status. None of this requires or grants access to anketa content — it's an authorization capability, entirely separate from the encryption model (see encryption.md's threat-model section for why that separation matters).

## Language

The interface (English, Russian, Latvian, Spanish) is a pure client-side preference, switchable anytime, independent of what language a given account's notification emails go out in — those follow whatever the account holder last set explicitly, since the two can reasonably differ (e.g. someone reading the UI in one language while a colleague, or their own inbox habits, expect emails in another).
