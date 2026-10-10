import { ApiError } from './api/client';

/**
 * What an expired link can still lead to — mirrors the expired states of
 * ActivationController::linkState() (GitHub issue #169):
 * - `available`: the invitee can ask whoever invited them for a new invite
 * - `requested`: they already have, within the last 24 hours
 * - `reissued`: a newer invite to the same address is still usable
 * - `signup`: a self-registration; signing up again sends a new link
 * - `create_company`: a cloud company creation; starting again sends a new link
 * - `none`: nobody to ask through the app
 */
type Renewal =
  'available' | 'requested' | 'reissued' | 'signup' | 'create_company' | 'none';

// A record, not a list, so a new Renewal member doesn't compile until it's here.
const RENEWALS: Record<Renewal, true> = {
  available: true,
  requested: true,
  reissued: true,
  signup: true,
  create_company: true,
  none: true,
};

/** Everything the activation page can show for its link, as one state. */
export type LinkState =
  | { kind: 'loading' }
  | { kind: 'ready'; email: string }
  | { kind: 'alreadyActive' }
  | { kind: 'expired'; renewal: Renewal }
  | { kind: 'invalid' }
  | { kind: 'error'; message: string | null };

/**
 * The link state an error from `GET /api/activation-tokens/{token}` or
 * `POST …/request-renewal` stands for, read from its status and `code`.
 * A renewal already requested comes back as 410 `requested`, like the lookup.
 * `null` for anything else (the per-IP rate limit, a failed send, a network
 * error), which the caller shows as an error instead.
 */
export function linkStateFromError(error: unknown): LinkState | null {
  if (!(error instanceof ApiError)) return null;
  const body = (error.body ?? {}) as { code?: unknown; renewal?: unknown };

  if (404 === error.status && 'invalid_token' === body.code) {
    return { kind: 'invalid' };
  }
  if (409 === error.status && 'already_activated' === body.code) {
    return { kind: 'alreadyActive' };
  }
  if (
    410 === error.status &&
    'expired' === body.code &&
    'string' === typeof body.renewal &&
    Object.hasOwn(RENEWALS, body.renewal)
  ) {
    return { kind: 'expired', renewal: body.renewal as Renewal };
  }
  return null;
}
