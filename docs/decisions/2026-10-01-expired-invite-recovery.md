# Expired invite recovery

Closes [GitHub issue #169](https://github.com/aleksejs1/encrypted1on1/issues/169).

## Problem

Activation links expire after 24 hours (`ActivationToken::TOKEN_TTL_HOURS`). Opening one later, or
opening a link that was already used, showed the same dead end: "Invalid or expired activation
link." An invite sent on a Friday afternoon was dead by Monday, the invitee couldn't tell who to ask,
and someone who had activated on one device and clicked the link again on another assumed sign-up
had failed.

## Decision

- **The lookup says what happened.** `GET /api/activation-tokens/{token}` still answers 200 with the
  email for a usable link. Otherwise `ActivationController::linkState()` picks one state: 404
  `invalid_token`, 409 `already_activated` (the link was used, or its address has an account by
  now, even through another still-valid invite), or 410 `expired` with a `renewal` hint.
  `renewal` is one of `available`, `requested`, `reissued`, `signup`, `create_company` or `none`, so the activation page renders one
  state (`frontend/src/activationLink.ts`), not a set of flags. On the backend the states are one
  enum, `App\Http\ActivationLinkState`, matched exhaustively. `POST …/complete` answers an
  unusable link the same way, so a form opened before expiry and submitted after it switches to
  the expired state and its renewal option.
- **Expired links are kept for 14 days**, with new indexes on `activation_tokens.email` and
  `expiresAt` for the lookups and the cleanup. `app:cleanup-expired-tokens` used to delete an activation
  token as soon as it expired, which made an expired link indistinguishable from an unknown one. It
  now keeps it `ActivationToken::RETENTION_DAYS_AFTER_EXPIRY` (14) days past expiry. An expired row
  can never be redeemed (`isUsable()` checks `expiresAt`), and only its hash is stored. Password
  reset tokens are unchanged.
- **"Request new invitation"** (`POST /api/activation-tokens/{token}/request-renewal`) emails whoever
  can re-invite the person, in that user's own locale. It never issues a token itself; the inviter
  re-sends the invite as usual, through the existing `POST /api/invites` with its permission, rate
  limit and seat-limit checks.
- **One rule, `App\Invite\InviteRenewal`, for both sides.** The activation page asks it about the
  expired link's address and the admin list asks it about each newest row, so "Request new
  invitation" is offered exactly when the admin's row offers "Re-send". A renewal belongs to the
  address, not the link: it is recorded on the newest invite to that address in the company
  (`InviteRecordRepository::findNewestFor()`), whichever old link was clicked, even one with no
  invite record of its own. So several old links share one cooldown, and the admin's badge sits
  on the row they re-send from. "An account exists" is checked across all companies (plain SQL,
  so an admin's `CompanyFilter` can't hide it), since `users.email` is unique app-wide. The admin
  list asks about all its rows at once (`resendableAmong()`), in a fixed number of queries.
- **The token decides the company, not the session.** `CompanyFilterListener` leaves
  `CompanyFilter` off for the three activation routes, so a visitor still logged in to one company (on Cloud, say) who opens a link
  into another sees that link's real state, not "invalid". Every query there is scoped by the
  token's company explicitly.
- **Abuse limits:** an IP-keyed limiter (`invite_renewal_request`, 30/hour, env-overridable; 30
  rather than 5 because a Monday morning of new hires behind one office NAT shares it) and a
  24-hour cooldown per address (`InviteRecord::$renewalRequestedAt`, new column, SQLite and MySQL
  migrations). The cooldown is claimed with a conditional `UPDATE … WHERE renewalRequestedAt IS NULL
  OR renewalRequestedAt <= :cutoff` (`InviteRecordRepository::claimRenewalRequest()`), the same claim
  shape as `AnketaRepository::claimReminder()`, so two concurrent clicks send one email. A request
  within the cooldown gets the same 410 `requested` as the lookup, not a separate 429. If every
  send fails, the claim is released and the invitee gets a 503 instead of "Request sent".
- **The admin side.** `GET /api/admin/invites` carries `renewalRequestedAt` and `resendable`: the
  newest invite to an address (ties on the second-precision `createdAt` go to the time-ordered id),
  when it expired unused and the link itself would still offer a renewal: the address wasn't
  scrubbed by an account deletion, has no account by now, and has no other link that still works.
  "Still works" is one rule in `ActivationTokenRepository`, also used by `SeatLimitChecker`. "Newest" is one
  ordering in `InviteRecordRepository`, shared by the list and by `findNewestFor()`. Such a row gets a
  **Re-send invite** button on `/admin/invites`, plus a **Renewal requested** badge if the invitee
  asked, dated, since a request stays shown past its cooldown until the invite is re-sent. Older
  rows for the same address are superseded and get neither.

### Where this differs from the issue

- **Who is asked.** The issue sends to the inviter, falling back to the company admins only when the
  inviter is gone. This sends to the inviter only while their account isn't deleted or blocked and
  the app still gives them an invite form: admins always, anyone else only in
  `REGISTRATION_MODE=invite` (that's when Account settings shows the form). Otherwise the company's
  active, unblocked admins get it. If nobody qualifies, the link reads as `none`, on the lookup
  too, so the button is never offered for a request nobody could act on. Since the recipient
  varies, the invitee's confirmation doesn't name who was told.
- **Self-registration.** The issue's admin fallback also covered `invitedBy = null`. That means a
  self-registration, so nobody invited the person. While signing up again would work (`domain`
  mode, and not on Cloud, which turns sign-up off), the page offers that (`signup`). Otherwise the
  admins are asked (unless the address isn't a valid
  email: sign-up only checked its domain suffix, and it would land in the admins' inbox), like for an invite whose inviter is gone, and
  their list offers Re-send for the row. The CLI bootstrap and cloud company creation have no
  `InviteRecord` at all. On Cloud such an admin link is almost always a company creation, so it
  reads as `create_company` and links to `/create-company`, with copy that also covers a
  CLI-bootstrapped first admin (the two can't be told apart from the token). Starting again makes
  a new company, so a newer link to the address in any company makes the old one read as
  `reissued`. Elsewhere the CLI bootstrap reads as `none`. Nothing is renewable in a suspended
  company, whose admins can't log in, nor for an address outside the company's allowed email
  domain (changed since the invite), which neither sign-up nor an admin's re-send would accept.
- **Not handled: an admin re-sending at the same moment.** A renewal request that read the old
  invite just before an admin's re-send still notifies; the cost is one superfluous email. And of
  two simultaneous requests, the one that lost the claim answers "requested" even if the winner's
  send then fails and releases it; a reload shows the button again.
- **Not handled: another company's tokens on account deletion.** `AccountDeleter` removes the
  user's spent tokens in their own company only, the same scoping it uses for `InviteRecord`; rows
  in another company for the same address go with the daily cleanup.
- **Not handled: a blocked account.** Its old link reads as already activated and points to the
  login page, which then explains the block.
- **States the issue didn't cover.** An expired link whose address has since registered (through a
  newer invite) reads as `already_activated`, not as renewable. An expired link with a newer
  still-usable invite to the same address, in the same company, reads as `reissued` ("use the most
  recent email") and can't be renewed, so an invitee who was already re-invited can't email the
  inviter again.
- **The email link.** The issue proposed `/admin/invites?reinvite=<email>` with a confirmation step.
  Admins land on `/admin/invites`, where the row's Re-send button is that one click. A non-admin
  inviter can't open that page, so they land on `/account?invite=<email>`, with the invite form
  filled in.
- **No lifetime in the copy.** The page says "This activation link has expired", not "invitation …
  after 24 hours": the six translations can't go stale if `TOKEN_TTL_HOURS` changes, and
  self-registration and company-creation links aren't invitations.
- **Not handled: address case.** Emails aren't normalized anywhere in the app. On MySQL, whose
  collation compares case-insensitively, `Bob@x` and `bob@x` count as one address in the SQL
  checks but as two in the admin list's newest-row pass; on SQLite they are two everywhere. That
  is an app-wide email-normalization question, not specific to this change.
- **The "Request sent" state survives a reload**, since the lookup itself answers `requested` within
  the cooldown. A client-side 24-hour cooldown, as the issue described, would have been lost on
  reload.

### Account deletion

Retaining tokens for two weeks means a deleted user's address would otherwise linger in
`activation_tokens`. `AccountDeleter` removes that user's used or expired tokens, scoped to their
company and in the same flush, with the same pending-row exception it already applies to
`InviteRecord`. An old link of a deleted account then reads as unknown, not as "already active".

## Alternatives considered

- **Look the email up in `InviteRecord` instead of keeping tokens.** `InviteRecord` has no token
  hash, by design (see its docblock), so a raw token can't be matched to it once the token row is
  gone. Adding the hash there would make it a second token table.
- **A client-only cooldown** (disable the button for 24 hours in the browser): trivially bypassed,
  and lost on reload. The server-side claim was needed anyway for concurrent clicks.

## Verification

- Seven independent `code-review` rounds (the maintainer capped the loop at seven). Each round
  found real edge cases in the renewal rule, most from the same root: the activation page and the
  admin list deciding "renewable" separately. Rounds 4–5 moved it into one service
  (`InviteRenewal`) with a batched form for the list. Round 7's one logic finding (an address
  outside a changed allowed domain was still offered a renewal) was fixed with a test and not
  re-reviewed; its two refactoring notes were left as they are.

- Backend: `InviteRenewalTest` covers every lookup state, the request (exact recipients and their
  locale and link, the cooldown, a request after it, the admin fallback for a deleted, blocked or
  non-admin-outside-`invite`-mode inviter, blocked admins skipped, an older link recorded on the
  newest invite once, a failed send releasing the claim, the per-IP limit), `complete()` on an
  expired link, and the admin list's `resendable` (superseded, tie-broken, pending, scrubbed). `InviteRecordRepositoryTest` covers the claim and release,
  `InvitationNotifierTest` the email, `CleanupExpiredTokensCommandTest` the retention window, and
  `AccountDeleterTest` the token removal.
- Frontend unit tests for `linkStateFromError()`.
- `e2e/invite-renewal.spec.ts` against the real e2e stack: an expired invite is renewed from the link
  (focus lands on the confirmation, which survives a reload), the admin sees the badge and re-sends,
  the old row loses its button, and the old link then reads as reissued. Also the already-activated
  and unknown-link pages.
- Migrations (`Version20261001122844`, both histories) generated by `app:make-dual-migration`,
  trimmed to the `invite_records` column and the two `activation_tokens` indexes, and run
  up/down/up on SQLite and a throwaway MySQL 8.4.
