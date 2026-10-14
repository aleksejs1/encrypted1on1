/**
 * The Plan page states the server decides between (GET /api/admin/billing's
 * `state`), in the order it checks them. Must stay the cases of
 * App\\Billing\\BillingState (a test compares).
 */
export const BILLING_STATES = [
  'custom',
  'plus-past-due',
  'plus-ending',
  'plus',
  'free-unavailable',
  'free',
] as const;

export type BillingState = (typeof BILLING_STATES)[number];
