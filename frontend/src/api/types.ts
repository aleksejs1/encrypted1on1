/**
 * Shared shapes for backend JSON responses. Single source of truth for the
 * fields each resource actually carries — call sites that only need a few
 * of them should narrow with `Pick<>` rather than re-declaring their own
 * copy, so a backend field rename is a compile error everywhere it's used
 * instead of a silent `undefined` in whichever copy nobody updated.
 */
import type { Side, TemplateKey } from '../anketa/questions';
import type { Goal } from '../anketa/goals';

/** GET /api/me */
export interface MeResponse {
  id: string;
  email: string;
  displayName: string;
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
  displayName: string;
  publicKey: string;
}

/** One row of GET /api/anketas. */
export interface AnketaSummary {
  id: string;
  myRole: Side;
  counterpartId: string;
  counterpartEmail: string;
  counterpartName: string;
  meetingDate: string;
  myPublishedAt: string | null;
  counterpartPublishedAt: string | null;
  archivedAt: string | null;
  missed: boolean;
  counterpartKeyOutdated: boolean;
  counterpartDeleted: boolean;
  /** The question-set version this anketa was created against — see frontend/src/anketa/questions.ts. */
  formVersion: number;
  /** Which built-in meeting-type template this anketa uses — see frontend/src/anketa/questions.ts. */
  templateKey: TemplateKey;
  /** Null only on legacy anketas from before periodicity existed. */
  periodicityDays: number | null;
  /** See AnketaDetail's own `oneOff` — GitHub issue #111. */
  oneOff: boolean;
}

/** GET /api/anketas/{id} */
export interface AnketaDetail {
  id: string;
  myRole: Side;
  counterpartId: string;
  counterpartEmail: string;
  counterpartName: string;
  /** Served by the backend all along; the private-notes panel (GitHub issue #132) is the first to read it here. */
  counterpartDeleted: boolean;
  meetingDate: string;
  archivedAt: string | null;
  mySealedKey: string;
  employeeBlob: string | null;
  employeePublishedAt: string | null;
  employeeBlobVersion: number;
  managerBlob: string | null;
  managerPublishedAt: string | null;
  managerBlobVersion: number;
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
  /** The question-set version this anketa was created against — see frontend/src/anketa/questions.ts. */
  formVersion: number;
  /** Which built-in meeting-type template this anketa uses — see frontend/src/anketa/questions.ts. */
  templateKey: TemplateKey;
  /**
   * Created by hand while the pair already had another open anketa — no
   * carry-forward, and archiving it never auto-creates a next meeting (GitHub
   * issue #111). Set once at creation, never changes.
   */
  oneOff: boolean;
}

/**
 * GET /api/anketas/{id}/live-state — a cheap polling target for the anketa
 * detail page's live-update mechanism (see private/live-updates-proposal.md,
 * not tracked in git). No blobs, so decrypting is never needed just to check
 * for a change — a diff against the previously-seen values is what tells the
 * page whether it needs to re-fetch AnketaDetail and decrypt anything.
 *
 * Not an exhaustive model of the response: the backend reuses summarize()
 * wholesale (see AnketaController::liveState()'s own docblock) rather than
 * trimming it down, so the real payload also carries `id`/`myRole`/
 * `counterpartId`/`counterpartEmail`/`counterpartName`/`periodicityDays`/
 * `counterpartKeyOutdated`/`counterpartDeleted`/`formVersion` — this
 * interface only declares the subset this page actually reads from it.
 *
 * The two exceptions: `templateKey` and `oneOff` are NOT in the real payload, unlike
 * everything else summarize() returns — AnketaPresenter::serializeLiveState() explicitly
 * strips them back out, since both are immutable once an anketa is created and have
 * nothing to poll for.
 *
 * Deliberately no goal-related field, so goal creates/edits never live-update
 * — unlike every blob here, `Goal` rows have no version counter, and goal
 * editing has no isolated "editing" boundary the way editingMyAnswers gives
 * post-publish answer edits (title/description/target-date are permanently-
 * editable inline inputs with a manual Save button). Live-refreshing them
 * could silently discard an unsaved, actively-typed edit. Out of scope for
 * this feature — a real follow-up, not an oversight — until goal editing
 * gets that same kind of edit-mode boundary first.
 */
export interface AnketaLiveState {
  myPublishedAt: string | null;
  counterpartPublishedAt: string | null;
  archivedAt: string | null;
  missed: boolean;
  meetingDate: string;
  employeeBlobVersion: number;
  managerBlobVersion: number;
  commentsVersion: number;
  outcomesVersion: number;
  goalCheckpointsVersion: number;
}
