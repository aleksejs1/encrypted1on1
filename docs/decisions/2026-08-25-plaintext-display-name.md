# Show names instead of raw email/uuid, via a new plaintext displayName field

## Problem

The UI identified people by raw email address (header, anketa list, the
counterpart-picker when creating a new anketa) or, in a couple of spots, a raw
user id — accurate, but reads oddly as an identifier and gives no way to tell
"who is this" at a glance the way a name does.

## Decision

Added `User::$displayName` — a new, optional, **plaintext** column, set at
registration (`Activate.svelte`) and editable afterward (a new "Display name"
card in `AccountSettings.svelte`, `PUT /api/me/display-name`). This is a
deliberate, narrow addition to CLAUDE.md's "assume encrypted" default,
alongside the goal-metadata exception already carved out there — same
reasoning as `User::$email` itself, which has always been plaintext: the
server already needs it outside any single end-to-end-encrypted context (here,
to appear in `GET /api/users`'s counterpart-picker and in the admin/
platform-admin user listings), and unlike anketa content there is no claim
anywhere in this product that a name is hidden from the server. It carries
exactly the same sensitivity as the email address sitting right next to it in
every one of those listings — nothing new is exposed that the server didn't
already see.

Display rules (`frontend/src/userDisplay.ts`) vary by how tight the layout is:
full name (or email, if unset) in the header and anketa list; "Name (email)"
in the counterpart-picker (`UserTypeahead.svelte`, `Report.svelte`'s target
picker); first name only inside an anketa's own two-column layout (page
title, side headings, comment/outcome/goal author tags), where a full name
would crowd the narrow columns. Empty string means "not set" — every site
falls back to the email, matching this field's pre-existing behavior exactly.

Server-side, the value is trimmed and stripped of C0/C1 control characters,
Unicode bidi-control characters (the LRO/RLO override family, LRI/RLI/FSI/PDI
isolates), and zero-width/BOM characters before being stored
(`App\Http\DisplayNameField`) — this value is echoed verbatim into the
counterpart-picker another user clicks to choose who to share a new anketa
with, so an unstripped bidi override could visually reorder it to impersonate
a different real name in that exact UI. Capped at the column's own 255
*characters* (`mb_strlen`, not `strlen` — a byte count would reject
legitimate Cyrillic/Latvian names well under the real limit).

## Alternatives considered

Separate `firstName`/`lastName` columns were considered and rejected: a
single free-text field is simpler to validate/audit, and "first word only"
(for the anketa page's tight layout) falls out of it directly
(`firstWord()`/`shortDisplayName()`) without needing two columns.

## Verification

Real round trip against the live dev stack (`make up`): registered two real
accounts (one with a name, one without) via the actual Activate.svelte UI
with real password-derived keys, confirmed the header/anketa-title/anketa-list
fallback-to-email behavior for the nameless account, confirmed the named
account's first name (not full name) shows inside the anketa when viewed by
the counterpart, and confirmed Account Settings both prefills the current
name and updates the header live (no reload) after saving a new one. Full
backend (`composer stan`/`cs`/`md`/`test`, 230 tests including the new
bidi-stripping and multi-byte-length cases) and frontend
(`check`/`format`/`knip`/`test`) sweeps green, plus `jscpd` and
`check-doc-links.mjs`. Throwaway accounts/anketas from the manual round trip
were deleted afterward; the dev database's real user count was confirmed
unchanged.
