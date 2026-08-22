/**
 * Shared shapes for backend JSON responses. Single source of truth for the
 * fields each resource actually carries — call sites that only need a few
 * of them should narrow with `Pick<>` rather than re-declaring their own
 * copy, so a backend field rename is a compile error everywhere it's used
 * instead of a silent `undefined` in whichever copy nobody updated.
 */
import type { Side } from '../anketa/questions';
import type { Goal } from '../anketa/goals';

/** GET /api/me */
export interface MeResponse {
  id: string;
  email: string;
  isAdmin: boolean;
  registrationMode: string;
  allowedEmailDomain: string;
  isDemo: boolean;
  isPlatformAdmin: boolean;
  publicKey: string;
  encryptedPrivateKey: string;
}

export interface UserSummary {
  id: string;
  email: string;
  publicKey: string;
}

/** One row of GET /api/anketas. */
export interface AnketaSummary {
  id: string;
  myRole: Side;
  counterpartId: string;
  counterpartEmail: string;
  meetingDate: string;
  myPublishedAt: string | null;
  counterpartPublishedAt: string | null;
  archivedAt: string | null;
  missed: boolean;
  counterpartKeyOutdated: boolean;
  counterpartDeleted: boolean;
}

/** GET /api/anketas/{id} */
export interface AnketaDetail {
  id: string;
  myRole: Side;
  counterpartId: string;
  counterpartEmail: string;
  meetingDate: string;
  archivedAt: string | null;
  mySealedKey: string;
  employeeBlob: string | null;
  employeePublishedAt: string | null;
  managerBlob: string | null;
  managerPublishedAt: string | null;
  commentsBlob: string | null;
  commentsVersion: number;
  outcomesBlob: string | null;
  outcomesVersion: number;
  goals: Goal[];
  goalCheckpointsBlob: string | null;
  goalCheckpointsVersion: number;
  counterpartPublicKey: string;
  periodicityDays: number | null;
  missed: boolean;
}
