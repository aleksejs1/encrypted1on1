/**
 * Shared shapes for backend JSON responses. Single source of truth for the
 * fields each resource actually carries — call sites that only need a few
 * of them should narrow with `Pick<>` rather than re-declaring their own
 * copy, so a backend field rename is a compile error everywhere it's used
 * instead of a silent `undefined` in whichever copy nobody updated.
 */
import type { AnketaTemplateKey, Side } from '../anketa/questions';
import type { Goal } from '../anketa/goals';
import type { TemplateDefinition } from '../anketa/templateDefinition';

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
  /** Which meeting-type template this anketa uses — see frontend/src/anketa/questions.ts. */
  templateKey: AnketaTemplateKey;
  /** Null only on legacy anketas from before periodicity existed. */
  periodicityDays: number | null;
  /** See AnketaDetail's own `oneOff` — GitHub issue #111. */
  oneOff: boolean;
}

/**
 * One row of GET /api/anketas: the summary plus, for an anketa on a company
 * template, that template's name as it was when the anketa was created
 * (GitHub issue #144). Not in the live-state poll.
 */
export interface AnketaListRow extends AnketaSummary {
  customTemplateName: string | null;
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
  discussedBlob: string | null;
  discussedVersion: number;
  counterpartPublicKey: string;
  periodicityDays: number | null;
  missed: boolean;
  /** The question-set version this anketa was created against — see frontend/src/anketa/questions.ts. */
  formVersion: number;
  /**
   * Which meeting-type template this anketa uses — see frontend/src/anketa/questions.ts.
   * `'custom'` is a company template's version, `customTemplateVersionId`.
   */
  templateKey: AnketaTemplateKey;
  /** The company template version a `'custom'` anketa renders; null otherwise. */
  customTemplateVersionId: string | null;
  /** That version's name; null unless `'custom'`. */
  customTemplateName: string | null;
  /**
   * Created by hand while the pair already had another open anketa — no
   * carry-forward, and archiving it never auto-creates a next meeting (GitHub
   * issue #111). Set once at creation, never changes.
   */
  oneOff: boolean;
  /**
   * The archive form's default "Next meeting type" (GitHub issue #140): what the
   * successor gets unless someone picks another type. Null for a one-off (no
   * successor) and once archived (no archive form).
   */
  nextCycleTemplateKey: AnketaTemplateKey | null;
  /**
   * The company template to preselect when `nextCycleTemplateKey` is `'custom'`
   * (GitHub issue #144): the template's id, so the successor gets its latest
   * version. Null otherwise.
   */
  nextCustomTemplateId: string | null;
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
  discussedVersion: number;
}

/** One row of GET /api/templates: an active company template (GitHub issue #144). */
export interface CompanyTemplate {
  id: string;
  name: string;
  description: string;
}

/** GET /api/template-versions/{id}: what a custom anketa renders. */
export interface TemplateVersion {
  name: string;
  /** Server-validated, but checked again before it's rendered. */
  definition: unknown;
}

/**
 * One row of GET /api/admin/templates (GitHub issue #142): a company template
 * with its current version's content. Also the `current` row of a 409
 * version_conflict from PUT /api/admin/templates/{id}.
 */
export interface AdminTemplate {
  id: string;
  currentVersion: number;
  archivedAt: string | null;
  updatedAt: string;
  name: string;
  description: string;
  definition: TemplateDefinition;
}
