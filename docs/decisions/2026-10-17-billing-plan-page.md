# The Plan page and a paid Plus plan in the Cloud deployment

## Problem

[GitHub issue #286](https://github.com/aleksejs1/encrypted1on1/issues/286) (this record is its
step E3, [#311](https://github.com/aleksejs1/encrypted1on1/issues/311)). In the Cloud
deployment (`CLOUD_MODE=1`) a company admin could pay for Plus only by calling the API by hand,
and after paying saw nothing in the app: no plan, no next payment date, no way to cancel. The
payment itself stored the Stripe ids and the status and lifted any suspension, a platform
admin's included, but the plan stayed `free` with the same seat limit. A failed payment, a normal
cancellation, or any subscription status the code didn't know suspended the whole company, so
people couldn't open their own 1:1s on the day of a meeting, although a free plan exists.
[#283](https://github.com/aleksejs1/encrypted1on1/issues/283) (the first sandbox run) and [#285](https://github.com/aleksejs1/encrypted1on1/issues/285) (12
gaps in the billing code) list the details.

## Decision

One new admin tab, **Plan** (`/admin/plan`), for company admins in the Cloud deployment only,
and the server rules behind it. The page shows the plan and the seats used, offers Plus with its
price, confirms a payment after the return from Stripe, and links to Stripe's customer portal.
What the server does, the variables, the Stripe settings and the operator rules are in
[`docs/deployment.md`](../deployment.md)'s "Stripe billing" section; what an admin sees is in
[`docs/user-flow.md`](../user-flow.md)'s "Admin" section.

No new exception to the encryption: everything here was already stored in plaintext, or is the
same kind of data (the plan, the seat limit, the subscription's status, two new dates
`subscriptionPeriodEndsAt`/`subscriptionCancelsAt`, Stripe ids). Nothing about a 1:1 reaches
Stripe, and of the admin only the account language, for the portal: the checkout sends the
company's id and its stored Stripe customer id, not the admin's email (see `docs/encryption.md`).

The design proposal had eleven open questions for the maintainer, each with a recommendation.
#286 was planned and built on the recommended option for all eleven:

1. **Not paying moves a company to Free; it never suspends it.** While the subscription is
   `active`, `trialing` or `past_due` the company is on Plus; in any other status, ones Stripe
   adds later included, on Free (`Company::applySubscription()`). Suspension stays a platform
   admin's manual tool, and the webhook neither sets nor lifts it. Rejected: suspension after
   non-payment, as before. It locks people out of their own records over a billing problem, and
   a suspended company's admin can't log in to pay.
2. **A company over the Free limit after a downgrade keeps everyone; it only can't invite or
   take new sign-ups.**
   Rejected: a warning, a deadline and then a suspension. That brings back what decision 1
   removed. Accepted: a company can pay once, invite up to the Plus limit and cancel. If that
   becomes common a softer measure can be added later.
3. **Seat limits follow the landing page: Free 100, Plus 1,000,** set by `DEFAULT_SEAT_LIMIT`
   and `PLUS_SEAT_LIMIT` and read only by `App\Billing\Plan`. Variables, not constants, because
   another operator may run cloud mode with their own numbers, as they set their own price in
   Stripe. Rejected: constants in the code, or keeping the old 5 seats and changing the landing
   page. A limit changes only together with the plan, so one set by hand lasts until then.
4. **The "3 months of history on Free" promise is not implemented and not mentioned on the
   page.** The page compares the plans by seats only, the one thing the code enforces. Rejected:
   deleting history now. It is a separate product decision, and once it exists a move from Plus
   to Free becomes irreversible and needs a warning and a delay. The landing page's text is
   [e1o1-landing#2](https://github.com/aleksejs1/e1o1-landing/issues/2).
5. **The Plus price is read from Stripe when the Plus card is shown, with no cache**
   (`GET /api/admin/billing/plus-price`, three seconds at most). Rejected: a price in the
   translations, which drifts from what Stripe charges. A cache would be the backend's first,
   for a page that is opened rarely. When Stripe doesn't answer, the card says the price is on
   the payment page and keeps its button.
6. **After Checkout the page waits for the webhook by reading the plan again,** two seconds after
   each answer, for up to 30 seconds, then offers "Check again" and says not to pay again
   (`admin/planReturn.ts`). Rejected: sending the Checkout session id to the server so it asks
   Stripe itself. That doesn't depend on the webhook's speed, but it is one more endpoint, and
   neither option prevents a second payment (decision 11).
7. **Card, invoices and cancellation go to Stripe's customer portal;** the app has no forms of
   its own. Rejected: card, invoice and cancellation forms in the app. The portal means less
   code to audit, card details never pass through the app, and with Managed Payments Stripe
   issues the invoices as the seller. The price: the portal's features are set in
   the Stripe dashboard, not in the code, and it is the only way to cancel.
   [#287](https://github.com/aleksejs1/encrypted1on1/issues/287) saw a portal page with Managed
   Payments on offer cancellation, adding a payment method and the invoices. Cancelling there
   was run (#308), and so was replacing the card, also while a payment was overdue, which Stripe
   then charged at once (#287 and #308, on the production server against the sandbox).
8. **A custom (Enterprise) plan is set in the database by hand,** with a plan name other than
   `free` and `plus`, and the webhook leaves such a plan and its limit alone. Rejected: a plan
   field in the platform-admin panel, for cases that are rare.
9. **A support address comes from an optional `SUPPORT_EMAIL`;** without it those lines are not
   shown. Rejected: an address in the translations, which belongs to one operator.
10. **A failed payment is signalled by Stripe's email and by a warning on the Plan tab.** The app
    sends no billing emails of its own. Rejected: a mark on the Plan tab in the admin tab strip,
    visible from any admin page, which needs a field in `/api/me`. This choice holds only if
    Stripe's failed-payment emails are on: #287 saw one sent with Managed Payments on, from Link,
    and turning the setting on is a required setup step in `docs/deployment.md`.
11. **Two paying subscriptions for one company can't be fully prevented; they are detected,
    logged as an error, and refunded by hand.** The checkout refuses a company that already pays,
    but a Checkout page that is already open can be paid until it expires (35 minutes, the
    shortest Stripe allows plus a margin), so two admins, or two tabs, can each pay. Rejected:
    the webhook cancelling and refunding the second one itself, code that moves money on its
    own.

Two further choices of the proposal, outside the eleven:

- **The webhook applies the subscription's current state, read from Stripe, not the event's
  payload** (`App\Billing\SubscriptionSync`). Events late or out of order do no harm, and a
  subscription is matched to its company by the stored subscription id, its `company_id`
  metadata or the checkout's reference, never by the Stripe customer. The cost is one call to
  Stripe per event.
- **Raising the seat limit of companies created with the old 5 is a manual rollout step, not
  part of the migration.** 5 and 100 are one server's settings, and a migration run by another
  operator would silently replace a limit they may have set on purpose. The first review of the
  proposal suggested the migration; the second agreed with the manual step.

## Accepted limitations

- **Two paid subscriptions are only detected** (decision 11), and the log line is seen only by an
  operator who reads the container log: it is a log line, not an exception, so Sentry doesn't
  get it even when `SENTRY_DSN` is set. Without someone reading the log, the customer notices the
  second charge first.
- **The page trusts the return URL.** `?checkout=success` makes the page wait and say "Payment
  received" on the parameter's word alone; someone opening it from the browser history without
  paying sees 30 seconds of waiting and then "If you just paid…".
- **No end-to-end test in cloud mode.** The e2e stack runs without `CLOUD_MODE`, so
  `frontend/e2e/admin-plan.spec.ts` and `invite-seat-limit.spec.ts` answer the cloud-only API
  (and `/api/me`'s `cloudMode`) themselves. The whole flow was run by hand against the Stripe
  sandbox instead ([#308](https://github.com/aleksejs1/encrypted1on1/issues/308)), and its
  remaining checks on the production server, on MySQL under its own domain (#287, #308).
- Two events of one subscription handled at the same moment can be written in reverse order;
  both read the current state from Stripe, so they can differ only around a real change. No row
  lock. The same holds for the first events of two subscriptions of one company: both can be
  applied, the later write wins, and the second paying subscription is logged only at its next
  event: usually the other of its two first events, seconds later, but as late as its renewal
  if both of them raced.
- A company whose last admin deletes their account, or is blocked, keeps paying with nobody to
  cancel. There is no "last admin" guard, and the right one is wider than this work.
- The rollout steps in `docs/deployment.md` assume a server that never took a payment through
  the old checkout API. Payments taken by the old code are reconciled by hand: describing every
  way the old webhook could have stored them was attempted across review rounds and dropped,
  since live payments were never turned on on this project's own server (as the design
  proposal states; E4, #312, checks it). It has taken sandbox payments, for the checks of #287
  and #308; those were reset by hand after the runs, and any made since are cleared by a rollout
  step before the live keys take effect (`docs/deployment.md`).
- The Plan page says nothing about the Free plan's history promise (decision 4) until the
  landing page's text matches what the code does.
- Stripe's failed-payment email leads the payer to Link, not to the Plan page's portal. By
  [Stripe's documentation](https://docs.stripe.com/payments/managed-payments/how-it-works) the
  card can be changed there too; that path wasn't tried.

`docs/encryption.md`'s threat-model list of what the server sees now has a line for the
company's billing data, the two new dates included. `docs/architecture.md`'s stack table gets a
Stripe row; its list of what the server controls doesn't name company fields and stays as it is.

## Verification

The tracking issue's steps A1 to E3, each a PR through the review loop. Backend:
`BillingControllerTest` (the webhook, the checkout guards, the page's endpoints, with a fake
provider), `CompanyTest` (the plan rules), `Unit/Billing/*` (`Plan`, `SeatUsage`,
`BillingState`, the Stripe provider's request shapes). Frontend: `planPage.test.ts`,
`planReturn.test.ts`, `seatLimit.test.ts`, `latestRead.test.ts`, and the e2e specs named above.
Against the Stripe sandbox: the plan rules through the API (#298: payment, scheduled
cancellation with a test clock, a failed renewal, events sent again, a missing customer) and the
whole flow through the Plan page in a browser (#308), then the card, the failed renewal and its
email by a person on the production server (#287, #308). The runs, and what none covered, are
listed in `docs/deployment.md`'s "Stripe billing" section.
