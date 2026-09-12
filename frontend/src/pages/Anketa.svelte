<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet, apiPost, apiPut, ApiError } from '../api/client';
  import { formatDisplayDate } from '../datePreference.svelte';
  import DateInput from '../design/DateInput.svelte';
  import AnswerField from '../anketa/AnswerField.svelte';
  import CommentThread from '../anketa/CommentThread.svelte';
  import {
    addComment,
    deleteComment,
    editComment,
    type Comment,
  } from '../anketa/comments';
  import {
    clearDraftBackup,
    loadDraftBackup,
    saveDraftBackup,
  } from '../anketa/draftBackup';
  import {
    addOutcome,
    carryForwardOutcomes,
    deleteOutcome,
    editOutcome,
    toggleDone,
    type OutcomeItem,
  } from '../anketa/outcomes';
  import {
    addCheckpoint,
    type CheckpointStatusTag,
    type Goal,
    type GoalCheckpoint,
  } from '../anketa/goals';
  import {
    getQuestionsForSide,
    type Side,
    type Answers,
  } from '../anketa/questions';
  import { updateBlobWithRetry } from '../anketa/blobSync';
  import { isOverdue as computeIsOverdue } from '../anketa/isOverdue';
  import type { AnketaDetail, AnketaLiveState } from '../api/types';
  import {
    decryptBlob,
    encryptBlob,
    generateAnketaKey,
    sealAnketaKey,
    unsealAnketaKey,
  } from '../crypto/anketaKey';
  import { fromBase64 } from '../crypto/encoding';
  import { ensureUnlocked } from '../crypto/identity.svelte';
  import { loadMasterKey } from '../crypto/session';
  import { shortDisplayName } from '../userDisplay';

  const { id }: { id: string } = $props();

  let loadError = $state<string | null>(null);
  let detail = $state<AnketaDetail | null>(null);
  let counterpartSide = $state<Side | null>(null);
  let anketaKey = $state<Uint8Array | null>(null);
  let masterKey = $state<Uint8Array | null>(null);

  let myAnswers = $state<Answers>({});
  let counterpartAnswers = $state<Answers | null>(null);
  let myPublished = $state(false);
  let counterpartPublished = $state(false);
  let archived = $state(false);

  /**
   * My own published answers' edit session — see
   * docs/decisions/2026-09-07-editable-published-anketa-answers.md. Reachable states,
   * modeled up front per docs/architecture-invariants.md §2 rather than as independent
   * booleans that could combine into something nothing produces:
   *
   * - Not editing: `editingMyAnswers` false. Only reachable/offered when `myPublished &&
   *   !archived`; AnswerField is readonly.
   * - Editing: `editingMyAnswers` true, `savingAnswersEdit` false. AnswerField is
   *   writable; Save/Cancel shown.
   * - Saving: `editingMyAnswers` true, `savingAnswersEdit` true. A save request is in
   *   flight; Save/Cancel disabled to prevent a double-submit, and AnswerField itself
   *   goes back to readonly (`readonly={myPublished && (!editingMyAnswers ||
   *   savingAnswersEdit)}`) — not just to stop further top-level field edits from
   *   racing the in-flight blob, but because AnswerField's list fields have their own
   *   uncommitted-until-"Save" inline entry-edit state; without this, opening an entry's
   *   inline edit *during* this window and typing into it gets silently discarded the
   *   instant the request resolves and `editingMyAnswers` flips back to false, with no
   *   warning the text was lost.
   * - After a 409: handleSaveAnswersEdit() always exits back to "not editing" rather
   *   than retrying — a blind resubmit could silently overwrite someone's real save
   *   with this tab's stale draft. A same-tab version conflict (another of *my own*
   *   tabs saved first, since employeeBlob/managerBlob are never written by both
   *   participants) decrypts and loads the now-current content into myAnswers so the
   *   next "Edit" starts from real data, not the stale draft. The counterpart archiving
   *   mid-edit (a real race — nothing polls, so this tab only learns about it from the
   *   409) flips `archived` instead, matching state 6 (below).
   * - Archived: `archived` true. Editing is never offered, regardless of any
   *   in-progress local edit — matches the counterpart-archives-mid-edit case above.
   * - Also reset to "not editing" whenever `id` changes (load(), a new anketa entirely)
   *   — the router reuses this component instance across same-page navigation with no
   *   remount, so a left-open edit session must not leak into the next anketa.
   */
  let editingMyAnswers = $state(false);
  let savingAnswersEdit = $state(false);
  let myBlobVersion = $state(0);
  let answersBeforeEdit: Answers | null = null;

  /** Shared by every "is anything in this keyed record currently open/in-progress" derived below (anyEntryEditOpen, anyCommentThreadBusy, anyCheckpointAdding). */
  function anyTrue(record: Record<string, boolean>): boolean {
    return Object.values(record).some(Boolean);
  }

  /**
   * Keyed by field.id, mirrored from each AnswerField's own hasOpenEntryEdit
   * (list fields only — non-list fields never set theirs true). The outer
   * Save/Cancel below stay disabled while any is true, so clicking them can
   * never save-over or silently discard a list entry's typed-but-uncommitted
   * inline edit — same "one action open at a time" reasoning as
   * anotherOutcomeActionOpen, one level up.
   */
  let fieldsWithOpenEntryEdit = $state<Record<string, boolean>>({});
  const anyEntryEditOpen = $derived(anyTrue(fieldsWithOpenEntryEdit));

  let saveState = $state<'idle' | 'saving' | 'saved' | 'error'>('idle');
  let publishing = $state(false);
  let archiving = $state(false);
  let actionError = $state<string | null>(null);

  let periodicityDays = $state<number | null>(null);
  let missed = $state(false);
  let skipNextMeeting = $state(false);
  let nextMeetingDate = $state('');
  let rescheduleDate = $state('');
  let rescheduling = $state(false);
  let showReschedule = $state(false);

  const isOverdue = $derived(
    detail !== null && !archived && computeIsOverdue(detail),
  );

  let myUserId = $state('');
  /** Short display label per participant (first name, or full email if no name is set) — inside the anketa's tight layout, only the tighter comments/outcomes/goals author tags and the two side headings use this. */
  let authorNames = $state<Record<string, string>>({});
  let allComments = $state<Comment[]>([]);
  let allOutcomes = $state<OutcomeItem[]>([]);
  let newOutcomeText = $state('');
  let addingOutcome = $state(false);

  let editingOutcomeId = $state<string | null>(null);
  let editOutcomeText = $state('');
  let editOutcomeBusy = $state(false);
  let editOutcomeError = $state<string | null>(null);

  let confirmingDeleteOutcomeId = $state<string | null>(null);
  let deleteOutcomeBusy = $state(false);
  let deleteOutcomeError = $state<string | null>(null);

  /** Same "one open at a time" reasoning as CommentThread's anotherActionOpen. */
  const anotherOutcomeActionOpen = $derived(
    editingOutcomeId !== null || confirmingDeleteOutcomeId !== null,
  );

  /**
   * Live updates (poll a cheap endpoint, refresh whichever sections changed
   * without a manual reload) — see private/live-updates-proposal.md (not
   * tracked in git) for the full design. The `applied*` variables below are
   * the last value each section was actually refreshed to, not merely the
   * last value seen from a poll — kept distinct on purpose (see
   * pollLiveState()) so a section skipped for being locally busy keeps
   * showing up as "changed" on every subsequent tick until it's finally
   * applied, rather than being silently marked "seen" and never retried.
   */
  let appliedCommentsVersion = $state(0);
  let appliedOutcomesVersion = $state(0);
  let appliedGoalCheckpointsVersion = $state(0);
  let appliedCounterpartBlobVersion = $state(0);

  /**
   * Aggregates every CommentThread instance's own open-edit/delete/draft
   * state (there can be dozens on one page — comments-default-open-proposal.md
   * §2) up to this page level, the same `bind:`/keyed-record shape as
   * `fieldsWithOpenEntryEdit`/`anyEntryEditOpen` above. Comments share one
   * blob for the whole anketa, so this gate is necessarily page-wide, not
   * per-thread: one open reply box anywhere pauses live-refresh for every
   * comment thread until it closes — a bounded, self-healing trade-off
   * (resolves the moment that one box closes), not a per-thread merge.
   *
   * One flat record shared across four different id namespaces at once
   * (question-field, outcome, goal, and checkpoint ids) — every place an id
   * can stop existing (currently: outcome deletion, both the self-initiated
   * path and the live-poll-applied one) must explicitly call
   * pruneStaleBusyEntries() for it, or a stale `true` left behind
   * permanently blocks anyCommentThreadBusy-gated live refresh for the rest
   * of the session. A future id-bearing removal path (e.g. goal deletion,
   * if that's ever added) needs the same treatment — namespacing the keys
   * (e.g. prefixing by type) would make this structural instead of
   * per-call-site, but wasn't worth the churn for what's currently exactly
   * one removable namespace.
   */
  let commentThreadsBusy = $state<Record<string, boolean>>({});
  const anyCommentThreadBusy = $derived(anyTrue(commentThreadsBusy));

  /** Ids highlighted for a few seconds after arriving via a live update (never on initial load) — see CommentThread's recentlyArrivedIds prop doc. Record, not a Set, matching this file's existing keyed-flag convention (fieldsWithOpenEntryEdit et al.) — always reassigned wholesale, never mutated in place. */
  let recentlyArrivedCommentIds = $state<Record<string, true>>({});

  function markRecentlyArrived(pollId: string, ids: string[]): void {
    if (ids.length === 0) return;
    recentlyArrivedCommentIds = {
      ...recentlyArrivedCommentIds,
      ...Object.fromEntries(ids.map((commentId) => [commentId, true as const])),
    };
    setTimeout(() => {
      // Guards against the same currently-unreachable same-page anketa-switch
      // case pollLiveStateFor's own pollId checks defend against (see that
      // function's docblock) — without this, a pending clear from the old
      // anketa could fire after switching to a new one and mutate this
      // Record for a since-abandoned session.
      if (id !== pollId) return;
      const next = { ...recentlyArrivedCommentIds };
      for (const commentId of ids) delete next[commentId];
      recentlyArrivedCommentIds = next;
    }, 3000);
  }

  let goals = $state<Goal[]>([]);
  let allCheckpoints = $state<GoalCheckpoint[]>([]);
  let newGoalTitle = $state('');
  let newGoalDescription = $state('');
  let newGoalTargetDate = $state('');
  let addingGoal = $state(false);
  let goalSaving = $state<Record<string, boolean>>({});
  let checkpointDraftText = $state<Record<string, string>>({});
  let checkpointDraftStatusTag = $state<
    Record<string, CheckpointStatusTag | ''>
  >({});
  let addingCheckpoint = $state<Record<string, boolean>>({});
  let goalsInfoOpen = $state(false);

  /** Same "don't refresh out from under an open draft" reasoning as anyCommentThreadBusy/anotherOutcomeActionOpen. */
  const anyCheckpointAdding = $derived(anyTrue(addingCheckpoint));

  let saveTimer: ReturnType<typeof setTimeout> | undefined;
  let loaded = false;

  $effect(() => {
    void id;
    // load() catches every error itself today, setting loadError — .catch() here
    // is so a future change to load() can't turn into a silent unhandled rejection.
    load().catch((error: unknown) => {
      console.error(error);
    });
  });

  async function load() {
    // The router reuses this component instance across a same-page navigation to a
    // different anketa id (App.svelte mounts <AnketaPage> with no {#key}, so `id`
    // changing re-runs the $effect above without a remount) — an answers-edit session
    // left open on the previous anketa must not leak into the next one's otherwise-
    // fresh state below.
    editingMyAnswers = false;
    savingAnswersEdit = false;
    answersBeforeEdit = null;
    fieldsWithOpenEntryEdit = {};
    commentThreadsBusy = {};
    recentlyArrivedCommentIds = {};
    try {
      const [identity, mk, anketa] = await Promise.all([
        ensureUnlocked(),
        loadMasterKey(),
        apiGet<AnketaDetail>(`/api/anketas/${id}`),
      ]);
      if (!mk) throw new Error($_('anketa.errorNotLoggedIn'));

      detail = anketa;
      masterKey = mk;
      myUserId = identity.userId;
      authorNames = {
        [identity.userId]: shortDisplayName(
          identity.displayName,
          identity.email,
        ),
        [anketa.counterpartId]: shortDisplayName(
          anketa.counterpartName,
          anketa.counterpartEmail,
        ),
      };
      counterpartSide = anketa.myRole === 'employee' ? 'manager' : 'employee';
      archived = anketa.archivedAt !== null;
      periodicityDays = anketa.periodicityDays;
      missed = anketa.missed;
      if (periodicityDays !== null) {
        // eslint-disable-next-line svelte/prefer-svelte-reactivity -- local scratch value, mutated once and read once, never stored in reactive state
        const defaultNext = new Date();
        defaultNext.setDate(defaultNext.getDate() + periodicityDays);
        nextMeetingDate = defaultNext.toISOString().slice(0, 10);
      }

      let key: Uint8Array;
      try {
        key = await unsealAnketaKey(
          anketa.mySealedKey,
          identity.publicKey,
          identity.privateKey,
        );
      } catch {
        // Wrong-key AEAD failure — this anketa was sealed under a keypair that no
        // longer matches identity.privateKey, most likely because the account went
        // through a password reset (a fresh keypair, see ResetPassword.svelte) since
        // this anketa was created. Nothing else in `detail` is usable without the
        // anketa key, so this is a distinct, terminal state for the page, not just
        // one field failing.
        loadError = $_('anketa.errorStaleKey');
        return;
      }
      anketaKey = key;
      appliedCommentsVersion = anketa.commentsVersion;
      appliedOutcomesVersion = anketa.outcomesVersion;
      appliedGoalCheckpointsVersion = anketa.goalCheckpointsVersion;

      if (anketa.commentsBlob) {
        const envelope = await decryptBlob<Comment[]>(anketa.commentsBlob, key);
        allComments = envelope.data;
      }

      if (anketa.outcomesBlob) {
        const envelope = await decryptBlob<OutcomeItem[]>(
          anketa.outcomesBlob,
          key,
        );
        allOutcomes = envelope.data;
      }

      goals = anketa.goals;
      if (anketa.goalCheckpointsBlob) {
        const envelope = await decryptBlob<GoalCheckpoint[]>(
          anketa.goalCheckpointsBlob,
          key,
        );
        allCheckpoints = envelope.data;
      }

      const myBlob =
        anketa.myRole === 'employee' ? anketa.employeeBlob : anketa.managerBlob;
      const myPublishedAt =
        anketa.myRole === 'employee'
          ? anketa.employeePublishedAt
          : anketa.managerPublishedAt;
      myPublished = myPublishedAt !== null;
      myBlobVersion =
        anketa.myRole === 'employee'
          ? anketa.employeeBlobVersion
          : anketa.managerBlobVersion;
      if (myBlob) {
        const envelope = await decryptBlob<Answers>(
          myBlob,
          myPublished ? key : mk,
        );
        myAnswers = envelope.data;
      }
      if (!myPublished) {
        // A present local backup is always at least as fresh as the last
        // confirmed server save (written on every edit, not debounced) —
        // safe to prefer unconditionally. See anketa/draftBackup.ts.
        const localBackup = await loadDraftBackup(id, mk);
        if (localBackup) myAnswers = localBackup;
      }

      const counterpartBlob =
        anketa.myRole === 'employee' ? anketa.managerBlob : anketa.employeeBlob;
      const counterpartPublishedAt =
        anketa.myRole === 'employee'
          ? anketa.managerPublishedAt
          : anketa.employeePublishedAt;
      counterpartPublished = counterpartPublishedAt !== null;
      appliedCounterpartBlobVersion =
        anketa.myRole === 'employee'
          ? anketa.managerBlobVersion
          : anketa.employeeBlobVersion;
      if (counterpartBlob && counterpartPublished) {
        const envelope = await decryptBlob<Answers>(counterpartBlob, key);
        counterpartAnswers = envelope.data;
      }

      loaded = true;
    } catch (error) {
      loadError =
        error instanceof ApiError ? error.message : $_('anketa.errorLoad');
    }
  }

  const LIVE_STATE_POLL_INTERVAL_MS = 4000;
  let livePollTimer: ReturnType<typeof setInterval> | undefined;

  /**
   * Starts/stops the live-update poll — see private/live-updates-proposal.md
   * (not tracked in git) for the full design. Runs while this page has a
   * usable anketaKey (nothing to decrypt, nothing worth polling for, before
   * that), paused via the Page Visibility API while the tab isn't visible,
   * and restarted whenever `id` changes (a same-page anketa switch).
   *
   * That last case is currently unreachable, not just theoretically safe:
   * `docs/decisions/2026-09-10-comment-thread-reuse-state-deferred.md`
   * confirmed no in-app navigation ever moves this mounted page from one
   * anketa id directly to another without an intervening full page load, so
   * `load()` never actually needs to reset `anketaKey`/`detail`/`archived`/
   * etc. mid-session today — same reasoning that decision already applied to
   * CommentThread's own local state. `pollLiveStateFor`'s `pollId` checks
   * below are cheap enough to keep as defense-in-depth regardless, but
   * building out a full cross-anketa reset of every piece of state this
   * feature reads would be exactly the speculative work against a
   * non-existent transition that decision already declined to do — revisit
   * together if that ever changes.
   */
  $effect(() => {
    void id;
    if (!anketaKey) return;

    function tick() {
      // pollLiveState() fully handles its own errors (including logging)
      // internally and never rejects — void, not .catch(), since a .catch()
      // here would be dead code that can never actually run.
      void pollLiveState();
    }

    function armInterval() {
      if (document.hidden || livePollTimer) return;
      livePollTimer = setInterval(tick, LIVE_STATE_POLL_INTERVAL_MS);
    }

    // Ticks immediately, then arms the interval — used for resuming after
    // real time has passed (the tab was hidden, or regained focus), where an
    // immediate check is actually likely to find something. Not used for the
    // very first start below: load() just fetched everything moments ago, so
    // an immediate tick there would only ever find "nothing changed."
    function resumePolling() {
      if (document.hidden || livePollTimer) return;
      tick();
      armInterval();
    }

    function stop() {
      clearInterval(livePollTimer);
      livePollTimer = undefined;
    }

    function handleVisibilityChange() {
      if (document.hidden) {
        stop();
      } else {
        resumePolling();
      }
    }

    armInterval();
    document.addEventListener('visibilitychange', handleVisibilityChange);
    window.addEventListener('focus', resumePolling);

    return () => {
      stop();
      document.removeEventListener('visibilitychange', handleVisibilityChange);
      window.removeEventListener('focus', resumePolling);
    };
  });

  /**
   * Guards against two ticks running concurrently — if a tick's own fetch/
   * decrypt work is still in flight when the next setInterval fire happens
   * (a slow connection, a large blob), that next fire just no-ops instead of
   * starting a second overlapping pollLiveState() call. Without this, two
   * in-flight calls could resolve out of order and the slower one's stale
   * write could land after and clobber the faster one's newer applied state.
   */
  let pollInFlight = false;

  /**
   * One poll tick: fetch the cheap scalar-only live-state, and only if
   * something actually differs from what's currently applied, fetch the
   * full (still-encrypted) detail once and apply whichever sections changed.
   * Never merges — every section is either replaced wholesale with the
   * fresh decrypted value, or skipped entirely for this tick because the
   * user has a local draft/in-flight action open for it (the next tick
   * re-checks for free). On any request failure (expired session, blocked
   * account, network blip), stop polling silently rather than retry-looping
   * or interrupting whatever the user is doing — their next explicit action
   * already surfaces an expired session the normal way.
   *
   * Captures `id` up front and re-checks it after every `await` — this page
   * is reused across a same-page anketa switch with no remount (see load()'s
   * own docblock), so without this a tick that straddles that switch could
   * mix live-state from the old anketa with full detail from the new one, or
   * decrypt the new anketa's blob for display under the stale scalars.
   */
  async function pollLiveState(): Promise<void> {
    if (!anketaKey || !detail || pollInFlight) return;
    const pollId = id;
    pollInFlight = true;
    try {
      await pollLiveStateFor(pollId);
    } catch (error) {
      // Any failure here — the live-state fetch, the full-detail fetch, or a
      // decrypt — stops the *current* interval silently rather than retry-
      // looping every 4s, matching this function's own documented intent. A
      // single try/catch around the whole tick, not just the first fetch, so
      // a transient failure partway through can't slip past it. Still logged
      // (unlike a user-facing error) — nothing else here ever surfaces a
      // failure, so without this, a live update silently ceasing to work
      // would leave no trace anywhere to debug from.
      //
      // Not a *permanent* stop: the next genuine visibility/focus event
      // (resumePolling(), not a tight loop) gets its own fresh attempt — for
      // a transient blip that's exactly the self-healing behavior wanted;
      // for a truly expired session, each such attempt just fails and stops
      // again, at the pace of the user actually switching tabs, never a
      // tight retry loop.
      console.error(error);
      clearInterval(livePollTimer);
      livePollTimer = undefined;
    } finally {
      pollInFlight = false;
    }
  }

  /**
   * Removes ids that no longer exist after a wholesale list replace —
   * otherwise a stale `true` left over from an item deleted elsewhere (self
   * or the counterpart) would permanently block anyCommentThreadBusy-gated
   * live refresh for the rest of the session, since nothing else ever prunes
   * this record.
   *
   * `commentThreadsBusy` is one shared record spanning several different id
   * namespaces at once (question-field ids, outcome ids, goal ids,
   * checkpoint ids), so this only ever clears ids the caller explicitly says
   * it owned *before* its own replace (`previousIds`) and no longer does
   * (`remainingIds`) — never anything else already `true` in the record. An
   * earlier version scanned the whole record for "true but not in
   * remainingIds," which looked right in isolation but actually cleared any
   * *other* namespace's busy id too (e.g. an outcome-scoped call wiping out
   * a field's own open comment edit) purely because that id wasn't an
   * outcome id — exactly the kind of shared-function-behavior-not-re-derived-
   * per-caller bug CLAUDE.md's working-style section calls out from the
   * multi-tab-unlock incident.
   */
  function pruneStaleBusyEntries(
    record: Record<string, boolean>,
    previousIds: string[],
    remainingIds: Set<string>,
  ): Record<string, boolean> {
    const removed = previousIds.filter((id) => !remainingIds.has(id));
    if (removed.length === 0) return record;
    const next = { ...record };
    for (const removedId of removed) next[removedId] = false;
    return next;
  }

  const EMPLOYEE_KEYS = {
    blob: 'employeeBlob',
    blobVersion: 'employeeBlobVersion',
    publishedAt: 'employeePublishedAt',
  } as const;
  const MANAGER_KEYS = {
    blob: 'managerBlob',
    blobVersion: 'managerBlobVersion',
    publishedAt: 'managerPublishedAt',
  } as const;

  async function pollLiveStateFor(pollId: string): Promise<void> {
    const live = await apiGet<AnketaLiveState>(
      `/api/anketas/${pollId}/live-state`,
    );
    if (id !== pollId || !detail || !anketaKey) return;

    // Captured before anything below can mutate editingMyAnswers (the
    // archivedChanged branch's exitAnswersEditSession() included) — the
    // whole point of gating myBlob's apply on "was there an active edit
    // session" is to protect whatever's unsaved in *this* tick; reading a
    // live value that this same tick may have already reset behind its back
    // would defeat that gate for exactly the tick that needs it most (an
    // own-blob version bump landing in the same tick as archival).
    const wasEditingMyAnswers = editingMyAnswers;

    // One computed key set per side, reused everywhere below instead of
    // re-deriving the same employee/manager ternary at each use site.
    const myKeys = detail.myRole === 'employee' ? EMPLOYEE_KEYS : MANAGER_KEYS;
    const counterpartKeys =
      detail.myRole === 'employee' ? MANAGER_KEYS : EMPLOYEE_KEYS;

    const archivedChanged = (live.archivedAt !== null) !== archived;
    const missedChanged = live.missed !== missed;
    const meetingDateChanged = live.meetingDate !== detail.meetingDate;
    const counterpartPublishedChanged =
      (live.counterpartPublishedAt !== null) !== counterpartPublished;
    // My own side is deliberately excluded from a publishedAt-transition trigger
    // here (only a post-publish blob edit, myBlobChanged below, is handled) —
    // before my own first publish, this page has no isolated "editing" boundary
    // the way editingMyAnswers gives post-publish edits (the draft form is always
    // directly editable), so silently overwriting myAnswers because *another of my
    // own tabs* published first could discard whatever this tab still has typed
    // but unsaved. That race already exists today (surfaced via publish()'s
    // existing 409 "already published" on this tab's own next Publish click), not
    // a new gap this feature needs to close.
    //
    // Also gated on `myPublished` itself, not just on the version differing —
    // this side's own myBlobVersion tracker is only ever advanced by the apply
    // block below, which itself only runs once myPublished is true (see the
    // reasoning above). Without this, the same "another of my own tabs
    // published+edited first" race that this page correctly declines to
    // auto-apply would leave myBlobChanged permanently true (since nothing
    // would ever move myBlobVersion to match), forcing an unbounded, never-
    // self-healing full-detail re-fetch every tick for the rest of the
    // session — unlike every other skip-while-busy case here, which clears
    // the moment the local busy state does.
    const myBlobChanged =
      myPublished && live[myKeys.blobVersion] !== myBlobVersion;
    const counterpartBlobChanged =
      live[counterpartKeys.blobVersion] !== appliedCounterpartBlobVersion;
    const commentsChanged = live.commentsVersion !== appliedCommentsVersion;
    const outcomesChanged = live.outcomesVersion !== appliedOutcomesVersion;
    const checkpointsChanged =
      live.goalCheckpointsVersion !== appliedGoalCheckpointsVersion;

    if (
      !archivedChanged &&
      !missedChanged &&
      !meetingDateChanged &&
      !counterpartPublishedChanged &&
      !myBlobChanged &&
      !counterpartBlobChanged &&
      !commentsChanged &&
      !outcomesChanged &&
      !checkpointsChanged
    ) {
      return;
    }

    // Scalars/banners: nothing on this page edits them inline, so always apply
    // immediately regardless of any other in-progress local action.
    if (archivedChanged) {
      archived = live.archivedAt !== null;
      if (archived) exitAnswersEditSession();
    }
    if (missedChanged) missed = live.missed;
    if (meetingDateChanged) {
      detail.meetingDate = live.meetingDate;
    }

    // Each busy-gated section's "will this tick actually apply it" flag is
    // computed once here and reused both to decide whether the full-detail
    // fetch below is even worth making, and (unchanged, further down) as the
    // apply block's own condition — a single source of truth, not two
    // independent copies of the same gate that could silently drift apart
    // (one loosened without the other, permanently stopping live updates for
    // that section with no error). A section that's changed but currently
    // busy would just have its result discarded unapplied anyway, so there's
    // no point fetching+decrypting the full detail for it alone. (The two
    // ungated ones, counterpart published/blob, have no busy gate at all —
    // always safe, always worth fetching for.) This tick still self-heals
    // the moment the busy section clears, same as always — nothing here
    // marks it "seen."
    // Outcomes/checkpoints are also gated on !anyCommentThreadBusy, not just
    // their own list-editing state — deleting an outcome/checkpoint whose
    // attached CommentThread has an open edit/delete would otherwise unmount
    // that thread mid-edit, silently discarding whatever wasn't saved yet.
    // Broader than strictly necessary (any comment thread busy anywhere
    // pauses outcome/checkpoint replacement too, not just a busy thread on
    // the specific item that would be removed), but the same bounded,
    // self-healing trade-off comments' own page-wide busy-gating already
    // accepts, not a new one invented for this.
    const willApplyComments = commentsChanged && !anyCommentThreadBusy;
    const willApplyOutcomes =
      outcomesChanged &&
      !anotherOutcomeActionOpen &&
      !addingOutcome &&
      !anyCommentThreadBusy;
    const willApplyCheckpoints =
      checkpointsChanged && !anyCheckpointAdding && !anyCommentThreadBusy;
    const willApplyMyBlob = myBlobChanged && !wasEditingMyAnswers;

    const needsFullDetail =
      counterpartPublishedChanged ||
      counterpartBlobChanged ||
      willApplyMyBlob ||
      willApplyComments ||
      willApplyOutcomes ||
      willApplyCheckpoints;
    if (!needsFullDetail) return;

    const fresh = await apiGet<AnketaDetail>(`/api/anketas/${pollId}`);
    if (id !== pollId || !detail || !anketaKey) return;

    // The decrypt-and-apply blocks below run sequentially, not Promise.all'd,
    // even though each operates on independent data — deliberately: every
    // decryptBlob call here is a fast, in-memory WASM operation on a small
    // blob (microseconds, not a network round trip), so the real time saved
    // by parallelizing is negligible, while doing it would mean re-deriving
    // the `id !== pollId`/`anketaKey` re-validation per branch instead of
    // once, for a tick that already returned early above unless something
    // actually changed. Not worth the complexity for this.
    //
    // Every commit below re-checks two things immediately before its
    // synchronous writes, *after* decrypting rather than merely as a
    // pre-condition for starting the decrypt:
    //
    // - "is fresh's version still at least as new as what's already
    //   applied" — a self-initiated save (updateComments/updateOutcomes/
    //   updateGoalCheckpoints) can complete during the `await
    //   decryptBlob(...)` below and bump the applied* tracker itself.
    // - "is this section still not busy" — the user can just as easily
    //   *start* a local edit during that same await window as finish one;
    //   the willApply* flags above were only ever a snapshot from before
    //   these awaits started.
    //
    // Re-checking only *before* the await (and trusting it to still hold
    // after) wouldn't close either window — both have to be read fresh,
    // after the await, right before the writes, since nothing else runs
    // between that check and the writes themselves.
    if (willApplyComments) {
      const decrypted = fresh.commentsBlob
        ? (await decryptBlob<Comment[]>(fresh.commentsBlob, anketaKey)).data
        : [];
      if (
        fresh.commentsVersion >= appliedCommentsVersion &&
        !anyCommentThreadBusy
      ) {
        const existingIds = new Set(allComments.map((c) => c.id));
        const newIds = decrypted
          .filter((c) => !existingIds.has(c.id))
          .map((c) => c.id);
        allComments = decrypted;
        appliedCommentsVersion = fresh.commentsVersion;
        markRecentlyArrived(pollId, newIds);
      }
    }

    if (willApplyOutcomes) {
      const previousIds = allOutcomes.map((o) => o.id);
      const decrypted = fresh.outcomesBlob
        ? (await decryptBlob<OutcomeItem[]>(fresh.outcomesBlob, anketaKey)).data
        : [];
      if (
        fresh.outcomesVersion >= appliedOutcomesVersion &&
        !anotherOutcomeActionOpen &&
        !addingOutcome &&
        !anyCommentThreadBusy
      ) {
        allOutcomes = decrypted;
        appliedOutcomesVersion = fresh.outcomesVersion;
        commentThreadsBusy = pruneStaleBusyEntries(
          commentThreadsBusy,
          previousIds,
          new Set(decrypted.map((o) => o.id)),
        );
      }
    }

    if (willApplyCheckpoints) {
      const previousIds = allCheckpoints.map((c) => c.id);
      const decrypted = fresh.goalCheckpointsBlob
        ? (
            await decryptBlob<GoalCheckpoint[]>(
              fresh.goalCheckpointsBlob,
              anketaKey,
            )
          ).data
        : [];
      if (
        fresh.goalCheckpointsVersion >= appliedGoalCheckpointsVersion &&
        !anyCheckpointAdding &&
        !anyCommentThreadBusy
      ) {
        allCheckpoints = decrypted;
        appliedGoalCheckpointsVersion = fresh.goalCheckpointsVersion;
        commentThreadsBusy = pruneStaleBusyEntries(
          commentThreadsBusy,
          previousIds,
          new Set(decrypted.map((c) => c.id)),
        );
      }
    }

    if (willApplyMyBlob) {
      // myBlobChanged already implies myPublished (see its own definition
      // above), so nothing further to check here for that.
      const myVersion = fresh[myKeys.blobVersion];
      const myBlob = fresh[myKeys.blob];
      const decrypted = myBlob
        ? (await decryptBlob<Answers>(myBlob, anketaKey)).data
        : undefined;
      if (myVersion >= myBlobVersion && !editingMyAnswers) {
        if (decrypted) myAnswers = decrypted;
        myBlobVersion = myVersion;
      }
    }

    if (counterpartPublishedChanged || counterpartBlobChanged) {
      // Always safe, no busy gate needed: the counterpart's own answers are
      // never edited from this session, so there's no local draft to protect.
      const counterpartVersion = fresh[counterpartKeys.blobVersion];
      const counterpartBlob = fresh[counterpartKeys.blob];
      const counterpartPublishedNow =
        fresh[counterpartKeys.publishedAt] !== null;
      const decrypted =
        counterpartBlob && counterpartPublishedNow
          ? (await decryptBlob<Answers>(counterpartBlob, anketaKey)).data
          : undefined;
      // Same "re-check right before the synchronous commit" guard every
      // other section above has, kept here too even though nothing in this
      // tab currently writes appliedCounterpartBlobVersion except this block
      // itself and load() — no reachable race today, but no reason for this
      // one commit to be the exception to a pattern every sibling follows.
      if (counterpartVersion >= appliedCounterpartBlobVersion) {
        counterpartPublished = counterpartPublishedNow;
        if (decrypted) counterpartAnswers = decrypted;
        appliedCounterpartBlobVersion = counterpartVersion;
      }
    }
  }

  function scheduleSave() {
    if (!loaded || myPublished || !masterKey) return;
    saveState = 'saving';
    clearTimeout(saveTimer);
    // saveDraft() catches every error itself today, setting saveState — .catch()
    // here is so a future change to saveDraft() can't turn into a silent unhandled
    // rejection.
    saveTimer = setTimeout(() => {
      saveDraft().catch((error: unknown) => {
        console.error(error);
      });
    }, 1000);
  }

  async function saveDraft() {
    if (!masterKey) return;
    try {
      const blob = await encryptBlob(myAnswers, masterKey);
      await apiPut(`/api/anketas/${id}/draft`, { blob });
      saveState = 'saved';
    } catch {
      saveState = 'error';
    }
  }

  async function handlePublish() {
    if (!anketaKey) return;
    publishing = true;
    actionError = null;
    try {
      clearTimeout(saveTimer);
      const blob = await encryptBlob(myAnswers, anketaKey);
      await apiPost(`/api/anketas/${id}/publish`, { blob });
      myPublished = true;
      clearDraftBackup(id);
    } catch (error) {
      actionError =
        error instanceof ApiError ? error.message : $_('anketa.errorPublish');
    } finally {
      publishing = false;
    }
  }

  function startEditingAnswers(): void {
    answersBeforeEdit = { ...myAnswers };
    editingMyAnswers = true;
    actionError = null;
  }

  function cancelEditingAnswers(): void {
    if (answersBeforeEdit) myAnswers = answersBeforeEdit;
    answersBeforeEdit = null;
    editingMyAnswers = false;
    actionError = null;
  }

  /**
   * Exits an in-progress answers-edit session because the anketa just
   * became archived — either discovered reactively (handleSaveAnswersEdit's
   * own 409 "already archived" branch) or proactively (the live-update
   * poll's archivedChanged branch). Deliberately narrower than
   * cancelEditingAnswers(): it doesn't touch myAnswers (the counterpart-
   * archives-mid-edit case leaves whatever was locally typed displayed,
   * readonly, unsaved — matching this page's own documented state model,
   * editingMyAnswers' docblock, "Archived" state) or actionError (each
   * caller sets/doesn't set that on its own terms). Shared so a third such
   * call site, if one is ever added, can't independently drift by resetting
   * only some of these three — same reasoning CLAUDE.md's working-style
   * section gives for not re-deriving shared invalidation logic per site.
   */
  function exitAnswersEditSession(): void {
    editingMyAnswers = false;
    savingAnswersEdit = false;
    answersBeforeEdit = null;
  }

  /**
   * No merge/retry-on-conflict here unlike updateField()'s comments/outcomes/
   * checkpoints pattern — those are shared blobs where reapplying the same edit to
   * fresh state is safe; myAnswers is a full-overwrite of the whole side; automatically
   * replaying it over someone else's newer save (even my own other tab's) could
   * silently discard real content. On a genuine version conflict, just surface the
   * server's fresh version and let the user decide to save again. On any other 409
   * (the only other one findAccessible/updateAnswers can produce is "anketa is now
   * archived" — the counterpart can archive from their own session at any time, a real
   * race, not a hypothetical), stop offering editing entirely — see the editingMyAnswers
   * docblock above for the full state list this maps onto.
   */
  async function handleSaveAnswersEdit(): Promise<void> {
    if (!anketaKey) return;
    savingAnswersEdit = true;
    actionError = null;
    try {
      const blob = await encryptBlob(myAnswers, anketaKey);
      const result = await apiPut<{ blobVersion: number }>(
        `/api/anketas/${id}/answers`,
        { blob, expectedVersion: myBlobVersion },
      );
      myBlobVersion = result.blobVersion;
      answersBeforeEdit = null;
      editingMyAnswers = false;
    } catch (error) {
      if (error instanceof ApiError && error.status === 409) {
        const conflict = error.body as {
          blobVersion?: number;
          blob?: string | null;
        } | null;
        if (conflict && typeof conflict.blobVersion === 'number') {
          // A genuine same-tab conflict (another of my own tabs saved first) — load
          // what's actually saved now rather than leaving this tab's stale edit sitting
          // in myAnswers, where a second Save click would otherwise silently overwrite
          // the other tab's real save with no warning. Exiting edit mode (instead of
          // retrying automatically) means the user has to explicitly re-open editing
          // on top of the now-current content, never blindly resubmit over it.
          myBlobVersion = conflict.blobVersion;
          if (anketaKey && typeof conflict.blob === 'string') {
            const envelope = await decryptBlob<Answers>(
              conflict.blob,
              anketaKey,
            );
            myAnswers = envelope.data;
          }
        } else {
          archived = true;
        }
        exitAnswersEditSession();
        actionError = error.message;
      } else {
        actionError =
          error instanceof ApiError
            ? error.message
            : $_('anketa.errorSaveAnswers');
      }
    } finally {
      savingAnswersEdit = false;
    }
  }

  /**
   * Auto-recreation (Phase 6d) is triggered from right here, not a server-side
   * background job — this browser already has the current anketa's key unsealed, so
   * it generates and seals the *next* anketa's key itself (same dance as
   * CreateAnketa.svelte) and sends the sealed keys along with the archive request.
   * The server never generates or even transiently holds an anketa key.
   */
  async function handleArchive(missedFlag: boolean): Promise<void> {
    if (!detail) return;
    archiving = true;
    actionError = null;
    try {
      let body: Record<string, unknown> = { missed: missedFlag };

      if (skipNextMeeting) {
        body.skipNextMeeting = true;
      } else {
        if (!anketaKey) throw new Error($_('anketa.errorNotReadyToArchive'));
        const identity = await ensureUnlocked();
        const nextKey = await generateAnketaKey();
        const mySealedKeyNext = await sealAnketaKey(
          nextKey,
          identity.publicKey,
        );
        const counterpartSealedKeyNext = await sealAnketaKey(
          nextKey,
          await fromBase64(detail.counterpartPublicKey),
        );
        // Carries forward from the current in-memory `allOutcomes`, not
        // `detail.outcomesBlob` (a page-load snapshot never written back to
        // after a save — self-initiated or, since this page now polls for
        // live updates, the counterpart's too) — archiving straight from a
        // stale snapshot would silently drop any outcome added/edited after
        // this page first loaded. Re-encrypting the current list under the
        // same (old) anketaKey first, then handing that fresh ciphertext to
        // carryForwardOutcomes, is simpler than giving that function a
        // separate already-decrypted-input code path for one caller.
        const currentOutcomesBlob = await encryptBlob(allOutcomes, anketaKey);
        const outcomesBlobNext = await carryForwardOutcomes(
          currentOutcomesBlob,
          anketaKey,
          nextKey,
        );

        body = {
          ...body,
          nextMeetingDate: new Date(nextMeetingDate).toISOString(),
          mySealedKey: mySealedKeyNext,
          counterpartSealedKey: counterpartSealedKeyNext,
          ...(outcomesBlobNext ? { outcomesBlob: outcomesBlobNext } : {}),
        };
      }

      await apiPost(`/api/anketas/${id}/archive`, body);
      archived = true;
      missed = missedFlag;
    } catch (error) {
      actionError =
        error instanceof ApiError ? error.message : $_('anketa.errorArchive');
    } finally {
      archiving = false;
    }
  }

  async function handleReschedule(): Promise<void> {
    if (!detail || !rescheduleDate) return;
    rescheduling = true;
    actionError = null;
    try {
      const isoDate = new Date(rescheduleDate).toISOString();
      // Reads the server's own DATE_ATOM-formatted value back from the
      // response rather than reusing the client's isoDate string for
      // detail.meetingDate — the two formats differ (ISO-with-millis vs.
      // DATE_ATOM), and live-state's own meetingDate always comes back in
      // the server's format, so comparing against a client-formatted string
      // here would never match, causing the poll's meetingDateChanged to
      // spuriously fire on the very next tick. (No separate applied*
      // tracker for this one, unlike the version counters — meetingDate is
      // compared directly against detail.meetingDate, which this is the
      // single source of truth for.)
      const result = await apiPut<{ meetingDate: string }>(
        `/api/anketas/${id}/meeting-date`,
        { meetingDate: isoDate },
      );
      detail = { ...detail, meetingDate: result.meetingDate };
      rescheduleDate = '';
      showReschedule = false;
    } catch (error) {
      actionError =
        error instanceof ApiError
          ? error.message
          : $_('anketa.errorReschedule');
    } finally {
      rescheduling = false;
    }
  }

  /**
   * Always re-reads the current commentsBlob/commentsVersion fresh rather than
   * trusting local state (which may be stale), per the Phase 6a plan. On a 409
   * (someone else wrote first), re-applies the same operation to the *latest*
   * remote state and retries once more, rather than merging a stale
   * already-computed result — the latter silently drops edits to an id the
   * remote write also touched (found while building outcomes' toggleDone,
   * which edits an existing item in place; add-only comments happened to work
   * either way, but this is the version that's actually correct in general).
   * A second conflict is rare enough to just surface as an error, not loop.
   */
  async function submitComment(targetId: string, text: string): Promise<void> {
    if (!anketaKey) return;
    await updateComments((current) =>
      addComment(current, targetId, myUserId, text),
    );
  }

  async function handleEditComment(
    commentId: string,
    text: string,
  ): Promise<void> {
    if (!anketaKey) return;
    await updateComments((current) =>
      editComment(current, commentId, myUserId, text),
    );
  }

  async function handleDeleteComment(commentId: string): Promise<void> {
    if (!anketaKey) return;
    await updateComments((current) =>
      deleteComment(current, commentId, myUserId),
    );
  }

  /**
   * Shared reapply-on-conflict update for the anketa's three optimistic-
   * concurrency blobs (comments, outcomes, goal checkpoints — see blobSync.ts):
   * refetch, apply the caller's mutation, save, and on a 409 retry once
   * against whatever the conflict response carries under the same field names.
   *
   * Also returns the version this save actually landed on (read straight from
   * the save endpoint's own success response, not guessed by incrementing the
   * pre-save value) so the caller can keep its live-update `applied*Version`
   * tracker (see pollLiveState()) in sync with a save it made itself. Without
   * this, a self-initiated save would leave that tracker one version behind
   * until the next poll tick's own full-detail fetch happened to catch it up
   * — and if this page's comments are all busy right at that moment (a page-
   * wide gate, see anyCommentThreadBusy), that catch-up never runs, so every
   * subsequent tick keeps re-fetching the full anketa for nothing, for as
   * long as anything anywhere stays busy.
   */
  async function updateField<T>(
    blobKey: 'commentsBlob' | 'outcomesBlob' | 'goalCheckpointsBlob',
    versionKey:
      'commentsVersion' | 'outcomesVersion' | 'goalCheckpointsVersion',
    endpoint: string,
    apply: (current: T) => T,
  ): Promise<{ items: T; version: number } | undefined> {
    if (!anketaKey) return undefined;
    const fresh = await apiGet<AnketaDetail>(`/api/anketas/${id}`);
    let savedVersion = fresh[versionKey];
    const items = await updateBlobWithRetry<T>(
      anketaKey,
      { blob: fresh[blobKey], version: fresh[versionKey] },
      apply,
      async (blob, expectedVersion) => {
        // Partial<Record<...>>, not a bare Record<string, number> — the real
        // response only ever has the one versionKey matching whichever
        // endpoint this call actually hit, and the `?? savedVersion`
        // fallback means an unexpectedly-missing key (a typo pairing the
        // wrong versionKey with the wrong endpoint, or a future backend
        // response-shape change) leaves the pre-save version in place
        // instead of silently writing undefined/NaN into it.
        const result = await apiPut<Partial<Record<typeof versionKey, number>>>(
          `/api/anketas/${id}/${endpoint}`,
          { blob, expectedVersion },
        );
        savedVersion = result[versionKey] ?? savedVersion;
      },
      (error) => {
        if (!(error instanceof ApiError) || error.status !== 409)
          return undefined;
        const conflict = error.body as Record<string, string | number | null>;
        return {
          blob: conflict[blobKey] as string | null,
          version: conflict[versionKey] as number,
        };
      },
    );
    return { items, version: savedVersion };
  }

  async function updateComments(
    apply: (current: Comment[]) => Comment[],
  ): Promise<void> {
    const result = await updateField<Comment[]>(
      'commentsBlob',
      'commentsVersion',
      'comments',
      apply,
    );
    if (result !== undefined) {
      allComments = result.items;
      appliedCommentsVersion = result.version;
    }
  }

  async function handleAddOutcome(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (!newOutcomeText.trim() || addingOutcome) return;

    addingOutcome = true;
    actionError = null;
    try {
      await updateOutcomes((current) =>
        addOutcome(current, myUserId, newOutcomeText.trim()),
      );
      newOutcomeText = '';
    } catch (error) {
      actionError =
        error instanceof ApiError
          ? error.message
          : $_('anketa.errorAddOutcome');
    } finally {
      addingOutcome = false;
    }
  }

  async function handleToggleOutcome(itemId: string): Promise<void> {
    try {
      await updateOutcomes((current) => toggleDone(current, itemId));
    } catch (error) {
      actionError =
        error instanceof ApiError
          ? error.message
          : $_('anketa.errorUpdateOutcome');
    }
  }

  function startEditOutcome(item: OutcomeItem): void {
    editingOutcomeId = item.id;
    editOutcomeText = item.text;
    editOutcomeError = null;
  }

  function cancelEditOutcome(): void {
    editingOutcomeId = null;
    editOutcomeText = '';
    editOutcomeError = null;
  }

  function startDeleteOutcome(itemId: string): void {
    confirmingDeleteOutcomeId = itemId;
    deleteOutcomeError = null;
  }

  async function handleEditOutcomeSubmit(
    event: SubmitEvent,
    itemId: string,
  ): Promise<void> {
    event.preventDefault();
    if (!editOutcomeText.trim() || editOutcomeBusy) return;

    editOutcomeBusy = true;
    editOutcomeError = null;
    try {
      await updateOutcomes((current) =>
        editOutcome(current, itemId, myUserId, editOutcomeText.trim()),
      );
      editingOutcomeId = null;
      editOutcomeText = '';
    } catch (error) {
      editOutcomeError =
        error instanceof ApiError
          ? error.message
          : $_('anketa.errorEditOutcome');
    } finally {
      editOutcomeBusy = false;
    }
  }

  async function handleDeleteOutcomeConfirm(itemId: string): Promise<void> {
    deleteOutcomeBusy = true;
    deleteOutcomeError = null;
    try {
      await updateOutcomes((current) =>
        deleteOutcome(current, itemId, myUserId),
      );
      confirmingDeleteOutcomeId = null;
      // The deleted outcome's own CommentThread instance unmounts right along
      // with it — without this, an id left `true` here (e.g. a comment edit
      // was open on this outcome's thread when it got deleted) would stay
      // stuck forever, since nothing else ever prunes commentThreadsBusy, and
      // anyCommentThreadBusy would then block live comment refresh for the
      // rest of the session over an id that no longer exists anywhere. Same
      // helper pollLiveStateFor uses for the equivalent counterpart-deletes-
      // it-via-live-refresh case — allOutcomes above is already the post-
      // delete list by this point.
      commentThreadsBusy = pruneStaleBusyEntries(
        commentThreadsBusy,
        [itemId],
        new Set(allOutcomes.map((o) => o.id)),
      );
    } catch (error) {
      deleteOutcomeError =
        error instanceof ApiError
          ? error.message
          : $_('anketa.errorDeleteOutcome');
    } finally {
      deleteOutcomeBusy = false;
    }
  }

  async function updateOutcomes(
    apply: (current: OutcomeItem[]) => OutcomeItem[],
  ): Promise<void> {
    const result = await updateField<OutcomeItem[]>(
      'outcomesBlob',
      'outcomesVersion',
      'outcomes',
      apply,
    );
    if (result !== undefined) {
      allOutcomes = result.items;
      appliedOutcomesVersion = result.version;
    }
  }

  async function handleAddGoal(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (!newGoalTitle.trim() || addingGoal) return;

    addingGoal = true;
    actionError = null;
    try {
      const goal = await apiPost<Goal>(`/api/anketas/${id}/goals`, {
        goalUuid: crypto.randomUUID(),
        title: newGoalTitle.trim(),
        description: newGoalDescription.trim() || null,
        targetDate: newGoalTargetDate || null,
      });
      goals = [...goals, goal];
      newGoalTitle = '';
      newGoalDescription = '';
      newGoalTargetDate = '';
    } catch (error) {
      actionError =
        error instanceof ApiError ? error.message : $_('anketa.errorAddGoal');
    } finally {
      addingGoal = false;
    }
  }

  /** Saves the goal's title/description/targetDate as currently edited in place — see the template's bind:value on the goal object fields. */
  async function handleSaveGoal(goal: Goal): Promise<void> {
    goalSaving = { ...goalSaving, [goal.id]: true };
    actionError = null;
    try {
      const updated = await apiPut<Goal>(
        `/api/anketas/${id}/goals/${goal.id}`,
        {
          title: goal.title,
          description: goal.description,
          targetDate: goal.targetDate,
        },
      );
      goals = goals.map((g) => (g.id === goal.id ? updated : g));
    } catch (error) {
      actionError =
        error instanceof ApiError ? error.message : $_('anketa.errorSaveGoal');
    } finally {
      goalSaving = { ...goalSaving, [goal.id]: false };
    }
  }

  async function handleUpdateGoalStatus(goal: Goal): Promise<void> {
    actionError = null;
    try {
      const updated = await apiPut<Goal>(
        `/api/anketas/${id}/goals/${goal.id}`,
        { status: goal.status },
      );
      goals = goals.map((g) => (g.id === goal.id ? updated : g));
    } catch (error) {
      actionError =
        error instanceof ApiError
          ? error.message
          : $_('anketa.errorUpdateGoalStatus');
    }
  }

  /**
   * Checkpoints are keyed by the goal's stable `goalUuid`, not its per-anketa row
   * `id` — a carried-forward goal gets a fresh row id every cycle (see the Phase 6c
   * plan), so only goalUuid lets a checkpoint's history survive carry-forward and be
   * reconstructed across anketas later (the report, Phase 6f).
   */
  async function handleAddCheckpoint(goalUuid: string): Promise<void> {
    const text = (checkpointDraftText[goalUuid] ?? '').trim();
    const statusTag = checkpointDraftStatusTag[goalUuid] || undefined;
    if (!text && !statusTag) return;

    addingCheckpoint = { ...addingCheckpoint, [goalUuid]: true };
    actionError = null;
    try {
      await updateGoalCheckpoints((current) =>
        addCheckpoint(
          current,
          goalUuid,
          myUserId,
          text || undefined,
          statusTag,
        ),
      );
      checkpointDraftText = { ...checkpointDraftText, [goalUuid]: '' };
      checkpointDraftStatusTag = {
        ...checkpointDraftStatusTag,
        [goalUuid]: '',
      };
    } catch (error) {
      actionError =
        error instanceof ApiError
          ? error.message
          : $_('anketa.errorAddCheckpoint');
    } finally {
      addingCheckpoint = { ...addingCheckpoint, [goalUuid]: false };
    }
  }

  async function updateGoalCheckpoints(
    apply: (current: GoalCheckpoint[]) => GoalCheckpoint[],
  ): Promise<void> {
    const result = await updateField<GoalCheckpoint[]>(
      'goalCheckpointsBlob',
      'goalCheckpointsVersion',
      'goal-checkpoints',
      apply,
    );
    if (result !== undefined) {
      allCheckpoints = result.items;
      appliedGoalCheckpointsVersion = result.version;
    }
  }

  /** "You" for the current viewer's own items, otherwise the counterpart's short name — used for outcome-item and goal author tags. */
  function authorLabel(authorId: string): string {
    return authorId === myUserId
      ? $_('anketa.you')
      : (authorNames[authorId] ?? authorId);
  }

  const GOAL_STATUS_KEYS: Record<Goal['status'], string> = {
    in_progress: 'anketa.goalStatusInProgress',
    achieved: 'anketa.goalStatusAchieved',
    cancelled: 'anketa.goalStatusCancelled',
  };
  const GOAL_STATUS_TAG_CLASSES: Record<Goal['status'], string> = {
    in_progress: 'tag-accent',
    achieved: 'tag-accent-2',
    cancelled: 'tag-neutral',
  };

  const CHECKPOINT_STATUS_TAG_KEYS: Record<CheckpointStatusTag, string> = {
    on_track: 'anketa.statusTagOnTrack',
    at_risk: 'anketa.statusTagAtRisk',
    blocked: 'anketa.statusTagBlocked',
  };
  const CHECKPOINT_STATUS_TAG_CLASSES: Record<CheckpointStatusTag, string> = {
    on_track: 'tag-accent-2',
    at_risk: 'tag-outline',
    blocked: 'tag-neutral',
  };

  // Reactive autosave: fires whenever myAnswers changes (property-level mutations from AnswerField included).
  $effect(() => {
    void JSON.stringify(myAnswers);
    scheduleSave();
    // Local backup, written immediately (not debounced like the server sync) —
    // protects against a silent server-sync failure within the debounce
    // window, not just its timing. See anketa/draftBackup.ts.
    if (loaded && !myPublished && masterKey) {
      // saveDraftBackup() has no internal try/catch (e.g. sessionStorage quota),
      // so unlike the other fire-and-forget calls in this file, this one can
      // genuinely reject — .catch() here isn't just future-proofing.
      saveDraftBackup(id, myAnswers, masterKey).catch((error: unknown) => {
        console.error(error);
      });
    }
  });
</script>

{#snippet lockIcon()}
  <span class="lock-hint" title={$_('anketa.encryptedHint')} aria-hidden="true">
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="2.5"
      stroke-linecap="round"
      stroke-linejoin="round"
    >
      <rect x="3" y="11" width="18" height="10" rx="2"></rect>
      <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
    </svg>
  </span>
{/snippet}

{#snippet openLockIcon()}
  <span
    class="lock-hint"
    title={$_('anketa.notEncryptedHint')}
    aria-hidden="true"
  >
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="2.5"
      stroke-linecap="round"
      stroke-linejoin="round"
    >
      <rect x="3" y="11" width="18" height="10" rx="2"></rect>
      <path d="M7 11V7a5 5 0 0 1 9.5-1.5"></path>
    </svg>
  </span>
{/snippet}

<main>
  {#if loadError}
    <p class="banner-error">{loadError}</p>
  {:else if !detail}
    <p class="text-muted">{$_('anketa.loading')}</p>
  {:else}
    <h1>
      {$_('anketa.titleWithCounterpart', {
        values: {
          name: shortDisplayName(
            detail.counterpartName,
            detail.counterpartEmail,
          ),
        },
      })}
    </h1>
    <p class="meta">
      <span class="text-muted"
        >{$_('anketa.meetingLabel')}
        {formatDisplayDate(detail.meetingDate)}</span
      >
      {#if archived}<span class="tag tag-neutral"
          >{$_('anketa.badgeArchived')}</span
        >{/if}
      {#if missed}<span class="tag tag-neutral">{$_('anketa.badgeMissed')}</span
        >{/if}
      {#if isOverdue}<span class="tag tag-outline"
          >{$_('anketa.badgeOverdue')}</span
        >{/if}
      {#if !archived && !isOverdue && !showReschedule}
        <button
          type="button"
          class="btn btn-ghost change-date-btn"
          onclick={() => (showReschedule = true)}
        >
          {$_('anketa.changeDate')}
        </button>
      {/if}
    </p>

    {#if !archived && !isOverdue && showReschedule}
      <div class="reschedule-row">
        <DateInput bind:value={rescheduleDate} disabled={rescheduling} />
        <button
          type="button"
          class="btn btn-secondary"
          onclick={handleReschedule}
          disabled={rescheduling || !rescheduleDate}
        >
          {rescheduling ? $_('anketa.rescheduling') : $_('anketa.reschedule')}
        </button>
        <button
          type="button"
          class="btn btn-ghost"
          onclick={() => {
            showReschedule = false;
            rescheduleDate = '';
          }}
          disabled={rescheduling}
        >
          {$_('anketa.cancel')}
        </button>
      </div>
    {/if}

    {#if isOverdue}
      <div class="card elev-sm overdue-card">
        <strong>{$_('anketa.overdueHeading')}</strong>
        <div class="reschedule-row">
          <DateInput bind:value={rescheduleDate} disabled={rescheduling} />
          <button
            type="button"
            class="btn btn-secondary"
            onclick={handleReschedule}
            disabled={rescheduling || !rescheduleDate}
          >
            {rescheduling ? $_('anketa.rescheduling') : $_('anketa.reschedule')}
          </button>
        </div>
        <p class="text-muted overdue-note">{$_('anketa.orIfDidNotHappen')}</p>
        <button
          type="button"
          class="btn btn-ghost cancel-missed-btn"
          onclick={() => handleArchive(true)}
          disabled={archiving}
        >
          {archiving ? $_('anketa.cancelling') : $_('anketa.cancelAsMissed')}
        </button>
      </div>
    {/if}

    <!-- My side -->
    <section class="card side-card">
      <div class="heading-row">
        <h2>
          {$_('anketa.mySideHeading', {
            values: {
              role: $_(
                detail.myRole === 'employee'
                  ? 'common.roleEmployee'
                  : 'common.roleManager',
              ),
            },
          })}
        </h2>
        {@render lockIcon()}
      </div>

      <div class="blocks">
        {#each getQuestionsForSide(detail.myRole, detail.formVersion) as question (question.id)}
          <div class="block">
            <h4>{$_(question.titleKey)}</h4>
            {#each question.fields as field (field.id)}
              <AnswerField
                {field}
                bind:value={myAnswers[field.id]}
                readonly={archived ||
                  (myPublished && (!editingMyAnswers || savingAnswersEdit))}
                bind:hasOpenEntryEdit={fieldsWithOpenEntryEdit[field.id]}
                anketaId={id}
              />
              {#if myPublished}
                <CommentThread
                  comments={allComments.filter((c) => c.targetId === field.id)}
                  {authorNames}
                  currentUserId={myUserId}
                  onSubmit={(text) => submitComment(field.id, text)}
                  onEdit={handleEditComment}
                  onDelete={handleDeleteComment}
                  bind:hasOpenAction={commentThreadsBusy[field.id]}
                  recentlyArrivedIds={recentlyArrivedCommentIds}
                />
              {/if}
            {/each}
          </div>
        {/each}
      </div>

      {#if archived && !myPublished}
        <!-- Never published before the anketa closed — a distinct message
             from the "published, then archived" branch below, not the same
             badgePublished text, since this side genuinely never published.
             Checked ahead of `!myPublished` so archived always wins here,
             matching the same "editing never offered once archived" rule
             editingMyAnswers' docblock already states for the post-publish
             side — this closes the pre-publish half of that same rule,
             which a real gap let a never-reloaded tab (routine now that the
             live-update poll can flip `archived` mid-session) slip past:
             the draft stayed editable and Publish stayed enabled with
             nothing checking archived here at all. -->
        <span class="tag tag-neutral side-publish-btn"
          >{$_('anketa.badgeArchived')}</span
        >
      {:else if !myPublished}
        <p class="text-muted save-state">
          {#if saveState === 'saving'}{$_(
              'anketa.savingDraft',
            )}{:else if saveState === 'saved'}{$_(
              'anketa.savedDraft',
            )}{:else if saveState === 'error'}{$_('anketa.saveError')}{/if}
        </p>
        <button
          type="button"
          class="btn btn-primary side-publish-btn"
          onclick={handlePublish}
          disabled={publishing || anyEntryEditOpen}
        >
          {publishing ? $_('anketa.publishing') : $_('anketa.publish')}
        </button>
      {:else if archived}
        <span class="tag tag-accent side-publish-btn"
          >{$_('anketa.badgePublished')}</span
        >
      {:else if editingMyAnswers}
        <div class="answers-edit-actions">
          <button
            type="button"
            class="btn btn-primary"
            onclick={handleSaveAnswersEdit}
            disabled={savingAnswersEdit || anyEntryEditOpen}
          >
            {savingAnswersEdit ? $_('anketa.saving') : $_('anketa.save')}
          </button>
          <button
            type="button"
            class="btn btn-ghost"
            onclick={cancelEditingAnswers}
            disabled={savingAnswersEdit || anyEntryEditOpen}
          >
            {$_('anketa.cancel')}
          </button>
        </div>
      {:else}
        <div class="answers-edit-actions">
          <span class="tag tag-accent side-publish-btn"
            >{$_('anketa.badgePublished')}</span
          >
          <button
            type="button"
            class="btn btn-ghost"
            onclick={startEditingAnswers}
          >
            {$_('anketa.editAnswers')}
          </button>
        </div>
      {/if}
    </section>

    <!-- Counterpart side -->
    <section class="card side-card">
      <div class="heading-row">
        <h2>
          {$_('anketa.counterpartSideHeading', {
            values: {
              name: shortDisplayName(
                detail.counterpartName,
                detail.counterpartEmail,
              ),
              role: counterpartSide
                ? $_(
                    counterpartSide === 'employee'
                      ? 'common.roleEmployee'
                      : 'common.roleManager',
                  )
                : '',
            },
          })}
        </h2>
        {@render lockIcon()}
      </div>
      {#if !counterpartAnswers}
        <p class="text-muted">{$_('anketa.notPublishedYet')}</p>
      {:else if counterpartSide}
        <div class="blocks">
          {#each getQuestionsForSide(counterpartSide, detail.formVersion) as question (question.id)}
            <div class="block">
              <h4>{$_(question.titleKey)}</h4>
              {#each question.fields as field (field.id)}
                <AnswerField
                  {field}
                  value={counterpartAnswers[field.id]}
                  readonly
                  anketaId={id}
                />
                <CommentThread
                  comments={allComments.filter((c) => c.targetId === field.id)}
                  {authorNames}
                  currentUserId={myUserId}
                  onSubmit={(text) => submitComment(field.id, text)}
                  onEdit={handleEditComment}
                  onDelete={handleDeleteComment}
                  bind:hasOpenAction={commentThreadsBusy[field.id]}
                  recentlyArrivedIds={recentlyArrivedCommentIds}
                />
              {/each}
            </div>
          {/each}
        </div>
      {/if}
    </section>

    <!-- Outcomes -->
    <section class="card">
      <div class="heading-row heading-row-tight">
        <h2>{$_('anketa.outcomesHeading')}</h2>
        {@render lockIcon()}
      </div>
      <p class="text-muted outcomes-note">{$_('anketa.outcomesNote')}</p>

      <div class="outcomes-list">
        {#each allOutcomes as item (item.id)}
          <div class="outcome-item">
            <div class="entry outcome-entry">
              <input
                type="checkbox"
                class="outcome-checkbox"
                checked={item.done}
                disabled={item.authorId !== myUserId ||
                  editingOutcomeId === item.id ||
                  confirmingDeleteOutcomeId === item.id}
                onchange={() => handleToggleOutcome(item.id)}
              />
              {#if editingOutcomeId === item.id}
                <form
                  class="edit-form"
                  onsubmit={(event) => handleEditOutcomeSubmit(event, item.id)}
                >
                  <input
                    type="text"
                    class="input"
                    bind:value={editOutcomeText}
                    disabled={editOutcomeBusy}
                  />
                  <button
                    type="submit"
                    class="btn btn-secondary"
                    disabled={editOutcomeBusy || !editOutcomeText.trim()}
                  >
                    {$_('commentThread.save')}
                  </button>
                  <button
                    type="button"
                    class="btn btn-ghost"
                    onclick={cancelEditOutcome}
                    disabled={editOutcomeBusy}
                  >
                    {$_('commentThread.cancel')}
                  </button>
                </form>
              {:else}
                <span class="entry-text" class:done={item.done}
                  >{item.text}</span
                >
                <span class="tag tag-neutral">{authorLabel(item.authorId)}</span
                >
                {#if item.authorId === myUserId}
                  {#if confirmingDeleteOutcomeId === item.id}
                    <span class="outcome-actions">
                      <button
                        type="button"
                        class="btn btn-ghost btn-action"
                        onclick={() => handleDeleteOutcomeConfirm(item.id)}
                        disabled={deleteOutcomeBusy}
                      >
                        {$_('commentThread.confirmDelete')}
                      </button>
                      <button
                        type="button"
                        class="btn btn-ghost btn-action"
                        onclick={() => (confirmingDeleteOutcomeId = null)}
                        disabled={deleteOutcomeBusy}
                      >
                        {$_('commentThread.cancel')}
                      </button>
                    </span>
                  {:else}
                    <span class="outcome-actions">
                      <button
                        type="button"
                        class="btn btn-ghost btn-action"
                        onclick={() => startEditOutcome(item)}
                        disabled={anotherOutcomeActionOpen}
                      >
                        {$_('commentThread.edit')}
                      </button>
                      <button
                        type="button"
                        class="btn btn-ghost btn-action"
                        onclick={() => startDeleteOutcome(item.id)}
                        disabled={anotherOutcomeActionOpen}
                      >
                        {$_('commentThread.delete')}
                      </button>
                    </span>
                  {/if}
                {/if}
              {/if}
            </div>
            {#if editingOutcomeId === item.id && editOutcomeError}
              <p class="banner-error">{editOutcomeError}</p>
            {/if}
            {#if confirmingDeleteOutcomeId === item.id && deleteOutcomeError}
              <p class="banner-error">{deleteOutcomeError}</p>
            {/if}
            <CommentThread
              comments={allComments.filter((c) => c.targetId === item.id)}
              {authorNames}
              currentUserId={myUserId}
              onSubmit={(text) => submitComment(item.id, text)}
              onEdit={handleEditComment}
              onDelete={handleDeleteComment}
              bind:hasOpenAction={commentThreadsBusy[item.id]}
              recentlyArrivedIds={recentlyArrivedCommentIds}
            />
          </div>
        {:else}
          <p class="text-muted">{$_('anketa.outcomesEmpty')}</p>
        {/each}
      </div>

      <form class="add-row" onsubmit={handleAddOutcome}>
        <input
          type="text"
          class="input"
          bind:value={newOutcomeText}
          placeholder={$_('anketa.outcomesPlaceholder')}
          disabled={addingOutcome}
        />
        <button
          type="submit"
          class="btn btn-secondary"
          disabled={addingOutcome || !newOutcomeText.trim()}
        >
          {addingOutcome ? $_('anketa.adding') : $_('anketa.add')}
        </button>
      </form>
    </section>

    <!-- Goals -->
    <section class="card">
      <div class="heading-row">
        <h2>{$_('anketa.goalsHeading')}</h2>
        {@render openLockIcon()}
        <button
          type="button"
          class="btn btn-icon btn-secondary goals-info-toggle"
          onclick={() => (goalsInfoOpen = !goalsInfoOpen)}
          aria-label={$_('anketa.moreInfo')}
        >
          <svg
            class="icon"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2.3"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
          >
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="16" x2="12" y2="11"></line>
            <line x1="12" y1="8" x2="12.01" y2="8"></line>
          </svg>
        </button>
      </div>
      {#if goalsInfoOpen}
        <p class="text-muted goals-info-note">
          {$_('anketa.goalsUnencryptedNote')}
        </p>
      {/if}

      <div class="goal-list">
        {#each goals as goal (goal.id)}
          {@const isMyGoal = goal.authorId === myUserId}
          <div class="goal-card">
            <div class="goal-header">
              {#if isMyGoal}
                <div class="field goal-title-field">
                  <label for="goal-title-{goal.id}"
                    >{$_('anketa.goalTitleLabel')}</label
                  >
                  <input
                    id="goal-title-{goal.id}"
                    type="text"
                    class="input"
                    bind:value={goal.title}
                  />
                </div>
              {:else}
                <strong class="goal-title-display">{goal.title}</strong>
              {/if}
              <span class="tag {GOAL_STATUS_TAG_CLASSES[goal.status]}"
                >{$_(GOAL_STATUS_KEYS[goal.status])}</span
              >
              <span class="tag tag-neutral">{authorLabel(goal.authorId)}</span>
            </div>

            {#if isMyGoal}
              <div class="field">
                <label for="goal-description-{goal.id}"
                  >{$_('anketa.goalDescriptionLabel')}</label
                >
                <textarea
                  id="goal-description-{goal.id}"
                  class="input"
                  value={goal.description ?? ''}
                  oninput={(e) => (goal.description = e.currentTarget.value)}
                ></textarea>
              </div>
            {:else if goal.description}
              <p class="goal-description">{goal.description}</p>
            {/if}

            {#if isMyGoal}
              <div class="field goal-target-date-field">
                <label for="goal-target-date-{goal.id}"
                  >{$_('anketa.goalTargetDateLabel')}</label
                >
                <DateInput
                  id="goal-target-date-{goal.id}"
                  bind:value={
                    () => goal.targetDate ?? '',
                    (v) => (goal.targetDate = v || null)
                  }
                />
              </div>
            {:else if goal.targetDate}
              <p class="text-muted goal-target-date-display">
                {$_('anketa.goalTargetDateLabel')}: {formatDisplayDate(
                  goal.targetDate,
                )}
              </p>
            {/if}

            {#if isMyGoal}
              <div class="goal-actions">
                <div class="field goal-status-field">
                  <label for="goal-status-{goal.id}"
                    >{$_('anketa.goalStatusLabel')}</label
                  >
                  <select
                    id="goal-status-{goal.id}"
                    class="input goal-status-select"
                    bind:value={goal.status}
                    onchange={() => handleUpdateGoalStatus(goal)}
                  >
                    <option value="in_progress"
                      >{$_('anketa.goalStatusInProgress')}</option
                    >
                    <option value="achieved"
                      >{$_('anketa.goalStatusAchieved')}</option
                    >
                    <option value="cancelled"
                      >{$_('anketa.goalStatusCancelled')}</option
                    >
                  </select>
                </div>
                <button
                  type="button"
                  class="btn btn-secondary goal-save-btn"
                  onclick={() => handleSaveGoal(goal)}
                  disabled={goalSaving[goal.id]}
                >
                  {goalSaving[goal.id]
                    ? $_('anketa.saving')
                    : $_('anketa.save')}
                </button>
              </div>
            {/if}

            <CommentThread
              comments={allComments.filter((c) => c.targetId === goal.id)}
              {authorNames}
              currentUserId={myUserId}
              onSubmit={(text) => submitComment(goal.id, text)}
              onEdit={handleEditComment}
              onDelete={handleDeleteComment}
              bind:hasOpenAction={commentThreadsBusy[goal.id]}
              recentlyArrivedIds={recentlyArrivedCommentIds}
            />

            <h4 class="checkpoints-heading">
              {$_('anketa.checkpointsHeading')}
            </h4>
            <div class="checkpoints">
              {#each allCheckpoints.filter((c) => c.goalId === goal.goalUuid) as checkpoint (checkpoint.id)}
                <div class="checkpoint-row">
                  <span class="text-muted checkpoint-date"
                    >{formatDisplayDate(checkpoint.createdAt)}</span
                  >
                  {#if checkpoint.text}<span class="checkpoint-text"
                      >{checkpoint.text}</span
                    >{/if}
                  {#if checkpoint.statusTag}
                    <span
                      class="tag {CHECKPOINT_STATUS_TAG_CLASSES[
                        checkpoint.statusTag
                      ]}"
                    >
                      {$_(CHECKPOINT_STATUS_TAG_KEYS[checkpoint.statusTag])}
                    </span>
                  {/if}
                  <CommentThread
                    comments={allComments.filter(
                      (c) => c.targetId === checkpoint.id,
                    )}
                    {authorNames}
                    currentUserId={myUserId}
                    onSubmit={(text) => submitComment(checkpoint.id, text)}
                    onEdit={handleEditComment}
                    onDelete={handleDeleteComment}
                    bind:hasOpenAction={commentThreadsBusy[checkpoint.id]}
                    recentlyArrivedIds={recentlyArrivedCommentIds}
                  />
                </div>
              {:else}
                <p class="text-muted">{$_('anketa.noCheckpointsYet')}</p>
              {/each}
            </div>

            {#if isMyGoal}
              <div class="checkpoint-form">
                <input
                  type="text"
                  class="input"
                  placeholder={$_('anketa.checkpointPlaceholder')}
                  value={checkpointDraftText[goal.goalUuid] ?? ''}
                  oninput={(e) =>
                    (checkpointDraftText = {
                      ...checkpointDraftText,
                      [goal.goalUuid]: e.currentTarget.value,
                    })}
                  disabled={addingCheckpoint[goal.goalUuid]}
                />
                <select
                  class="input"
                  value={checkpointDraftStatusTag[goal.goalUuid] ?? ''}
                  onchange={(e) =>
                    (checkpointDraftStatusTag = {
                      ...checkpointDraftStatusTag,
                      [goal.goalUuid]: e.currentTarget.value as
                        CheckpointStatusTag | '',
                    })}
                  disabled={addingCheckpoint[goal.goalUuid]}
                >
                  <option value="">{$_('anketa.noStatusTag')}</option>
                  <option value="on_track"
                    >{$_('anketa.statusTagOnTrack')}</option
                  >
                  <option value="at_risk">{$_('anketa.statusTagAtRisk')}</option
                  >
                  <option value="blocked"
                    >{$_('anketa.statusTagBlocked')}</option
                  >
                </select>
                <button
                  type="button"
                  class="btn btn-secondary"
                  onclick={() => handleAddCheckpoint(goal.goalUuid)}
                  disabled={addingCheckpoint[goal.goalUuid] ||
                    (!checkpointDraftText[goal.goalUuid]?.trim() &&
                      !checkpointDraftStatusTag[goal.goalUuid])}
                >
                  {addingCheckpoint[goal.goalUuid]
                    ? $_('anketa.addingCheckpoint')
                    : $_('anketa.addCheckpoint')}
                </button>
              </div>
            {/if}
          </div>
        {:else}
          <p class="text-muted">{$_('anketa.noGoalsYet')}</p>
        {/each}
      </div>

      <form class="add-goal-row" onsubmit={handleAddGoal}>
        <input
          type="text"
          class="input"
          bind:value={newGoalTitle}
          placeholder={$_('anketa.goalTitlePlaceholder')}
          disabled={addingGoal}
        />
        <input
          type="text"
          class="input"
          bind:value={newGoalDescription}
          placeholder={$_('anketa.goalDescriptionPlaceholder')}
          disabled={addingGoal}
        />
        <DateInput bind:value={newGoalTargetDate} disabled={addingGoal} />
        <button
          type="submit"
          class="btn btn-secondary"
          disabled={addingGoal || !newGoalTitle.trim()}
        >
          {addingGoal ? $_('anketa.addingGoal') : $_('anketa.addGoal')}
        </button>
      </form>
    </section>

    {#if actionError}
      <p class="banner-error">{actionError}</p>
    {/if}

    {#if !archived}
      <section class="card">
        <h2>{$_('anketa.archiveHeading')}</h2>
        <label class="radio archive-skip">
          <input
            type="checkbox"
            class="native-checkbox"
            bind:checked={skipNextMeeting}
          />
          {$_('anketa.skipNextMeeting')}
        </label>
        {#if !skipNextMeeting}
          <div class="field archive-date-field">
            <label for="next-meeting-date"
              >{$_('anketa.nextMeetingDateLabel')}</label
            >
            <DateInput id="next-meeting-date" bind:value={nextMeetingDate} />
          </div>
        {/if}
        <button
          type="button"
          class="btn btn-primary"
          onclick={() => handleArchive(false)}
          disabled={archiving}
        >
          {archiving ? $_('anketa.archiving') : $_('anketa.archive')}
        </button>
      </section>
    {/if}
  {/if}
</main>

<style>
  main {
    max-width: 46rem;
    margin: 0 auto;
    padding: 28px 24px 60px;
    display: flex;
    flex-direction: column;
    gap: 20px;
  }

  h1 {
    font-size: 26px;
    margin-bottom: 4px;
  }

  .meta {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    font-size: 13px;
    margin: 0;
  }

  .change-date-btn {
    padding: 4px 0;
    font-size: 12px;
  }

  .overdue-card {
    border: 1px solid color-mix(in srgb, var(--color-accent) 45%, transparent);
    gap: 10px;
  }

  .reschedule-row {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
  }

  .overdue-note {
    font-size: 12px;
    margin: 0;
  }

  .cancel-missed-btn {
    align-self: flex-start;
    padding: 4px 0;
  }

  .heading-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 4px;
  }

  .heading-row h2 {
    margin: 0;
    font-size: 19px;
  }

  .heading-row-tight {
    margin-bottom: 2px;
  }

  .lock-hint {
    display: inline-flex;
    width: 14px;
    height: 14px;
    flex: none;
    color: color-mix(in srgb, var(--color-text) 45%, transparent);
  }

  .lock-hint svg {
    width: 100%;
    height: 100%;
  }

  .icon {
    width: 14px;
    height: 14px;
  }

  .blocks {
    display: flex;
    flex-direction: column;
  }

  .block {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding-bottom: 18px;
    margin-bottom: 18px;
    border-bottom: 1px solid var(--color-divider);
  }

  .block:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
  }

  .block h4 {
    margin: 0;
  }

  .save-state {
    font-size: 12px;
    margin: 0;
  }

  .side-publish-btn {
    align-self: flex-start;
  }

  .answers-edit-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    align-self: flex-start;
  }

  .outcomes-note {
    font-size: 12px;
    margin: 0 0 8px;
  }

  .outcomes-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .outcome-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .entry {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 8px 10px;
    background: var(--color-bg);
    border-radius: var(--radius-sm);
    font-size: 13px;
    flex-wrap: wrap;
  }

  .outcome-entry {
    align-items: center;
  }

  .outcome-checkbox {
    width: 16px;
    height: 16px;
    flex: none;
  }

  .entry-text {
    flex: 1;
  }

  .entry-text.done {
    text-decoration: line-through;
  }

  .outcome-actions {
    display: flex;
    gap: 2px;
  }

  .edit-form {
    display: flex;
    flex: 1;
    min-width: 200px;
    gap: 6px;
  }

  .edit-form .input {
    flex: 1;
    min-height: 32px;
    font-size: 13px;
  }

  .add-row {
    display: flex;
    gap: 8px;
  }

  .add-row .input {
    flex: 1;
  }

  .goals-info-toggle {
    width: 20px;
    height: 20px;
  }

  .goals-info-note {
    font-size: 12px;
    margin: 0 0 10px;
    padding: 8px 10px;
    background: var(--color-bg);
    border-radius: var(--radius-sm);
  }

  .goal-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  .goal-card {
    border: 1px solid var(--color-divider);
    border-radius: var(--radius-md);
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .goal-header {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
  }

  .goal-title-field {
    flex: 1;
    min-width: 160px;
  }

  .goal-title-display {
    font-size: 14px;
  }

  .goal-description {
    font-size: 13px;
    margin: 0;
  }

  .goal-target-date-display {
    font-size: 11px;
    margin: 0;
  }

  .goal-target-date-field {
    max-width: 220px;
  }

  .goal-actions {
    display: flex;
    align-items: flex-end;
    gap: 8px;
    flex-wrap: wrap;
  }

  .goal-status-field {
    min-width: 160px;
  }

  .goal-status-select {
    width: auto;
  }

  .goal-save-btn {
    margin-bottom: 1px;
  }

  .checkpoints-heading {
    margin: 0;
  }

  .checkpoints {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .checkpoint-row {
    display: flex;
    gap: 8px;
    font-size: 12px;
    align-items: center;
    flex-wrap: wrap;
  }

  .checkpoint-date {
    width: 70px;
    flex: none;
  }

  .checkpoint-text {
    flex: 1;
  }

  .checkpoint-form {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  .checkpoint-form .input {
    flex: 1;
    min-width: 140px;
  }

  .add-goal-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 12px;
  }

  .add-goal-row .input:first-of-type {
    flex: 1;
    min-width: 140px;
  }

  .add-goal-row .input:nth-of-type(2) {
    flex: 2;
    min-width: 160px;
  }

  .archive-skip {
    margin-bottom: 10px;
  }

  .native-checkbox {
    position: static;
    opacity: 1;
    width: auto;
    height: auto;
  }

  .archive-date-field {
    max-width: 220px;
    margin-bottom: 12px;
  }
</style>
