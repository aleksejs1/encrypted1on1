import { ApiError } from '../api/client';

/**
 * What the invite form adds to a "no seats left" error (GitHub issue #309):
 * an admin in the Cloud deployment can change the plan, anyone else there
 * can ask an admin to, and a self-hosted instance has no plans at all.
 */
export type SeatLimitStep = 'view-plan' | 'ask-admin' | 'none';

export function seatLimitStep(viewer: {
  isAdmin: boolean;
  cloudMode: boolean;
}): SeatLimitStep {
  if (!viewer.cloudMode) return 'none';
  return viewer.isAdmin ? 'view-plan' : 'ask-admin';
}

/** The code InviteController::create() refuses with; a test reads the PHP file. */
export const SEAT_LIMIT_CODE = 'seat_limit_reached';

/** A refused invite as its banner shows it; `seatLimit` adds the next step. */
export interface InviteError {
  message: string;
  seatLimit: boolean;
}

/** `fallback`: the text for an error that isn't the server's. */
export function inviteError(error: unknown, fallback: string): InviteError {
  return {
    message: error instanceof ApiError ? error.message : fallback,
    seatLimit:
      error instanceof ApiError &&
      (error.body as { code?: unknown } | null)?.code === SEAT_LIMIT_CODE,
  };
}
