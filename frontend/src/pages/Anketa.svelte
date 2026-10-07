<script lang="ts">
  import { onDestroy, tick, untrack } from 'svelte';
  import { _ } from 'svelte-i18n';
  import { apiGet, apiPost, apiPut, ApiError } from '../api/client';
  import { abortOnDestroy, isAbortError } from '../api/abortOnDestroy';
  import AnswerBlock from '../anketa/AnswerBlock.svelte';
  import LockIcon from '../anketa/LockIcon.svelte';
  import AnketaHeader from '../anketa/AnketaHeader.svelte';
  import { goToArchiveSection } from '../anketa/archiveHeading';
  import { clearFollowUpHash, FOLLOW_UP_HASH } from '../anketa/followUpLinks';
  import { isOpenPastPeriod, nextCadenceDate } from '../anketa/longOpen';
  import {
    clearJustCreated,
    isJustCreated,
    startCreateAnother,
  } from '../anketa/createDefaults';
  import AnketaOutcomes from '../anketa/AnketaOutcomes.svelte';
  import AnketaTopics from '../anketa/AnketaTopics.svelte';
  import AnketaGoals from '../anketa/AnketaGoals.svelte';
  import AnketaArchiveSection from '../anketa/AnketaArchiveSection.svelte';
  import { archiveConfirmation } from '../anketa/archiveConfirmation';
  import PrivateNotes, {
    readNotesPanelHidden,
  } from '../anketa/PrivateNotes.svelte';
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
  import { hasAnswer } from '../anketa/answerDisplay';
  import { decryptDraft, hasAnyAnswer } from '../anketa/drafts';
  import {
    carryForwardOutcomeItems,
    type OutcomeItem,
  } from '../anketa/outcomes';
  import {
    carryForwardTopicItems,
    decryptTopics,
    type TopicItem,
  } from '../anketa/topics';
  import { TopicsSync } from '../anketa/topicsSync';
  import { pruneStaleBusyEntries } from '../anketa/commentThreadsBusy';
  import {
    decryptDiscussed,
    encodeDiscussed,
    type DiscussedBlobData,
  } from '../anketa/discussed';
  import { DiscussedSync } from '../anketa/discussedSync';
  import type { Goal, GoalCheckpoint } from '../anketa/goals';
  import {
    getQuestionsForSide,
    type Question,
    type Side,
    type Answers,
  } from '../anketa/questions';
  import {
    questionSetFromDefinition,
    type QuestionSet,
  } from '../anketa/customQuestions';
  import {
    defaultNextChoice,
    nextTemplateFields,
    type TemplateChoice,
  } from '../anketa/templateChoice';
  import {
    fetchCompanyTemplates,
    fetchTemplateVersion,
  } from '../api/templates';
  import { updateBlobWithRetry, versionConflictBody } from '../anketa/blobSync';
  import {
    beginAction,
    fallbackFocusOptions,
    findRow,
    ignoreHeldEnter,
    refocus,
    type ActionStart,
  } from '../anketa/keepFocus';
  import {
    answersFingerprint,
    isSaveShortcut,
    saveShortcutPlace,
    setAnswersUnloadWarning,
  } from '../anketa/answersEdit';
  import type {
    AnketaDetail,
    AnketaLiveState,
    CompanyTemplate,
  } from '../api/types';
  import {
    decryptBlob,
    encryptBlob,
    generateAnketaKey,
    sealAnketaKey,
    unsealAnketaKey,
  } from '../crypto/anketaKey';
  import { fromBase64 } from '../crypto/encoding';
  import { ensureUnlocked } from '../crypto/identity.svelte';
  import { navigate } from '../router.svelte';
  import { deriveDraftKey } from '../crypto/keypair';
  import { loadMasterKey } from '../crypto/session';
  import { shortDisplayName } from '../userDisplay';

  const { id }: { id: string } = $props();

  let loadError = $state<string | null>(null);
  let detail = $state<AnketaDetail | null>(null);
  let notesHidden = $state(readNotesPanelHidden());
  let counterpartSide = $state<Side | null>(null);
  let anketaKey = $state<Uint8Array | null>(null);
  /** Encrypts this side's unpublished draft — see crypto/keypair.ts's deriveDraftKey(). */
  let draftKey = $state<Uint8Array | null>(null);
  /**
   * The stored draft didn't decrypt (see anketa/drafts.ts): the form starts
   * blank with a notice, and autosave holds off until something is typed, so
   * merely opening the page never overwrites the stored ciphertext.
   */
  let draftUnreadable = $state(false);

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
   *   goes back to readonly (`mySideReadonly` below) — not just to stop further top-level field edits from
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
   * - Archiving from this tab is never combined with editing: the archive
   *   buttons are disabled while `editingMyAnswers`, and "Edit" is disabled
   *   while `archiving` — either order would otherwise strand an unsaved edit
   *   on an archived anketa (GitHub issue #130 review).
   * - Unsaved changes (GitHub issue #166) aren't a separate state: they're
   *   `answersEditUnsaved`, derived from myAnswers against the snapshot taken
   *   when editing started. Shown in the sticky edit bar, and a beforeunload
   *   warning is attached only while it's true.
   * - Also reset to "not editing" whenever `id` changes (load(), a new anketa entirely)
   *   — the router reuses this component instance across same-page navigation with no
   *   remount, so a left-open edit session must not leak into the next anketa.
   */
  let editingMyAnswers = $state(false);
  let savingAnswersEdit = $state(false);
  const mySideReadonly = $derived(
    archived || (myPublished && (!editingMyAnswers || savingAnswersEdit)),
  );
  /**
   * Whether my side renders the collapsed read-only view (unanswered fields
   * hidden, generic labels dropped — GitHub issue #131). Deliberately ignores
   * `savingAnswersEdit`, unlike `mySideReadonly`: collapsing the instant Save
   * is clicked (and re-expanding if it fails) would remount list fields and
   * lose their unsubmitted new-entry text.
   */
  const mySideCollapsed = $derived(
    archived || (myPublished && !editingMyAnswers),
  );
  /** Answer-field comments by field id — one pass per change, shared by every AnswerBlock's visibility check and `comments` prop. */
  const commentsByTarget = $derived.by(() => {
    // eslint-disable-next-line svelte/prefer-svelte-reactivity -- rebuilt whole on each allComments change and never mutated after; reactivity comes from the $derived
    const byTarget = new Map<string, Comment[]>();
    for (const comment of allComments) {
      const bucket = byTarget.get(comment.targetId);
      if (bucket) bucket.push(comment);
      else byTarget.set(comment.targetId, [comment]);
    }
    return byTarget;
  });
  let myBlobVersion = $state(0);
  /** A plain snapshot of myAnswers from when editing started; what Cancel restores. */
  let answersBeforeEdit = $state.raw<Answers | null>(null);

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

  /**
   * Whether the answers edit has anything not saved yet (GitHub issue
   * #166): a changed answer, or a list entry's inline edit still open with
   * its text not yet applied (counted as soon as it's open, even unchanged:
   * Save is disabled until it's applied or cancelled anyway, and a warning
   * too many beats a lost entry). Not "Add an entry" text that was never
   * added: it isn't an answer, and Save doesn't keep it. Also true while the
   * save is in flight.
   */
  const answersFingerprintBeforeEdit = $derived(
    answersBeforeEdit && answersFingerprint(answersBeforeEdit),
  );
  const answersEditUnsaved = $derived(
    editingMyAnswers &&
      (anyEntryEditOpen ||
        answersFingerprint(myAnswers) !== answersFingerprintBeforeEdit),
  );
  $effect(() => {
    setAnswersUnloadWarning(answersEditUnsaved);
    return () => setAnswersUnloadWarning(false);
  });

  let saveState = $state<'idle' | 'saving' | 'saved' | 'error'>('idle');
  let publishing = $state(false);
  let archiving = $state(false);
  /** closeMeeting() is running: the publish before the archive, then the archive. */
  let closingMeeting = $state(false);
  // What closing the meeting asks to confirm first (GitHub issue #229): it
  // never goes ahead over my own unpublished answers, see closeMeeting().
  const myDraftHasAnswers = $derived.by(() => {
    if (!detail || myPublished) return false;
    // A company template whose questions couldn't be loaded: there are no
    // fields to check the draft against, so any content in it counts.
    if (detail.templateKey === 'custom' && customQuestions.status !== 'ready') {
      return hasAnyAnswer(myAnswers);
    }
    return hasAnswer(questionsFor(detail.myRole), myAnswers);
  });
  const closeConfirmation = $derived(
    archiveConfirmation({
      myPublished,
      counterpartPublished,
      myDraftHasAnswers,
    }),
  );
  // Closing waits for an open edit of my published answers, and, when it
  // would publish my draft, for an open list-entry edit, like Publish does.
  const closeBlockedByEdit = $derived(
    editingMyAnswers || (closeConfirmation.publishFirst && anyEntryEditOpen),
  );
  let actionError = $state<string | null>(null);

  let periodicityDays = $state<number | null>(null);
  let missed = $state(false);
  let skipNextMeeting = $state(false);
  let nextMeetingDate = $state('');
  // The archive form's "Next meeting type" (GitHub issue #140). Page-level for the same reason as
  // skipNextMeeting/nextMeetingDate: handleArchive() reads it for both buttons.
  // Null only when there's no picker (a one-off, or already archived).
  let nextTemplateChoice = $state<TemplateChoice | null>(null);
  /** The picker's default, from the detail: what an untouched form gets. */
  let nextTemplateDefault = $state<TemplateChoice | null>(null);
  /** The picker's company templates (GitHub issue #144); empty if they couldn't be loaded. */
  let companyTemplates = $state<CompanyTemplate[]>([]);

  /**
   * A custom anketa's questions (GitHub issue #144, #133 §7.3), fetched from
   * its template version after the detail. Until they're there, or if they
   * can't be loaded, the answer sections aren't shown; the rest of the page,
   * the archive card included, is, so a pair is never stuck on this anketa.
   */
  type CustomQuestionsState =
    | { status: 'builtin' }
    | { status: 'loading' }
    | { status: 'failed' }
    | { status: 'ready'; questions: QuestionSet };
  let customQuestions = $state<CustomQuestionsState>({ status: 'builtin' });
  let pageMain = $state<HTMLElement>();

  let myUserId = $state('');
  /** Short display label per participant (first name, or full email if no name is set) — inside the anketa's tight layout, only the tighter comments/outcomes/goals author tags and the two side headings use this. */
  let authorNames = $state<Record<string, string>>({});
  let allComments = $state<Comment[]>([]);
  let allOutcomes = $state<OutcomeItem[]>([]);

  /**
   * `editingOutcomeId`/`confirmingDeleteOutcomeId`/`addingOutcome` live here
   * rather than fully inside AnketaOutcomes (which owns the rest of that
   * section's state and every one of its handlers) because
   * pollLiveStateFor's `willApplyOutcomes` gate below reads them directly —
   * they're passed down via `bind:` so AnketaOutcomes' own handlers still
   * mutate this same underlying state.
   */
  let editingOutcomeId = $state<string | null>(null);
  let confirmingDeleteOutcomeId = $state<string | null>(null);
  let addingOutcome = $state(false);

  /**
   * Same "one open at a time" reasoning as CommentThread's anotherActionOpen.
   * Passed down to AnketaOutcomes as a plain prop (read there, not
   * re-derived) — this is the one and only place this formula lives, since
   * pollLiveStateFor's willApplyOutcomes gate below also needs it.
   */
  const anotherOutcomeActionOpen = $derived(
    editingOutcomeId !== null || confirmingDeleteOutcomeId !== null,
  );

  /**
   * The shared "Topics to discuss" list (GitHub issue #206): one TopicsSync
   * per loaded anketa, like discussedSync below, created once its key and
   * list are known. Null until then, which hides the card. `allTopics`
   * mirrors its list.
   */
  // $state.raw: a class instance, replaced whole, never mutated through the proxy.
  let topicsSync = $state.raw<TopicsSync | null>(null);
  let allTopics = $state<TopicItem[]>([]);

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
   * The "discussed" checkboxes (GitHub issue #168): one DiscussedSync per
   * loaded anketa, created once its key and list are known. Null until then,
   * which hides the checkboxes. The two $state values mirror its view.
   */
  // $state.raw: a class instance, replaced whole, never mutated through the proxy.
  let discussedSync = $state.raw<DiscussedSync | null>(null);
  let discussedQuestions = $state<DiscussedBlobData>([]);
  let discussedError = $state<string | null>(null);

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
   * path in AnketaOutcomes and the live-poll-applied one below) must
   * explicitly call pruneStaleBusyEntries() for it, or a stale `true` left
   * behind permanently blocks anyCommentThreadBusy-gated live refresh for
   * the rest of the session. A future id-bearing removal path (e.g. goal
   * deletion, if that's ever added) needs the same treatment — namespacing
   * the keys (e.g. prefixing by type) would make this structural instead of
   * per-call-site, but wasn't worth the churn for what's currently exactly
   * one removable namespace. (A question field's thread also unmounts when
   * the collapsed read-only view hides its now-empty field — GitHub issue
   * #131. That path needs no prune: a field whose thread has an edit/delete
   * open still has comments and so stays shown, and CommentThread clears its
   * own entry on unmount anyway.) Passed down to both AnketaOutcomes and
   * AnketaGoals via `bind:` — AnketaOutcomes already reassigns the record
   * wholesale on delete; AnketaGoals doesn't yet (goals/checkpoints have no
   * delete path today), but is `$bindable` anyway, precisely so that the
   * future removal path this docblock already anticipates can safely call
   * pruneStaleBusyEntries() there without also having to remember to widen
   * this prop's contract at the same time — see AnketaGoals.svelte's own
   * matching docblock on its `commentThreadsBusy` prop.
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
  /**
   * Lives here rather than inside AnketaGoals (which owns the rest of the
   * goals/checkpoints section's state and every one of its handlers)
   * because pollLiveStateFor's `willApplyCheckpoints` gate below reads
   * `anyCheckpointAdding` directly — passed down via `bind:` so
   * AnketaGoals' own handleAddCheckpoint still mutates this same underlying
   * state.
   */
  let addingCheckpoint = $state<Record<string, boolean>>({});

  /** Same "don't refresh out from under an open draft" reasoning as anyCommentThreadBusy/anotherOutcomeActionOpen. */
  const anyCheckpointAdding = $derived(anyTrue(addingCheckpoint));

  let saveTimer: ReturnType<typeof setTimeout> | undefined;
  let loaded = false;
  let header: AnketaHeader | undefined = $state();

  // Cancels load()'s own detail fetch on unmount — see GitHub issue #95. Not
  // extended to pollLiveState's fetches below: those are already bounded by
  // the interval being cleared on unmount/id-change (see that $effect's own
  // cleanup), and a single readAbort here would only ever fire once (at
  // actual component destroy), not per id-change, so it wouldn't help there
  // even if applied.
  const readAbort = abortOnDestroy();
  // No more discussed saves once the page is gone (one in flight finishes).
  onDestroy(() => {
    discussedSync?.stop();
    topicsSync?.stop();
  });
  // The "1:1 created." offer is for the visit right after creating it only:
  // dropped when this page moves to another 1:1 (the instance is reused
  // across ids) or is left.
  $effect(() => {
    void id;
    return clearJustCreated;
  });

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
    loadGeneration++;
    editingMyAnswers = false;
    savingAnswersEdit = false;
    answersBeforeEdit = null;
    draftUnreadable = false;
    fieldsWithOpenEntryEdit = {};
    commentThreadsBusy = {};
    recentlyArrivedCommentIds = {};
    // untrack: this runs synchronously inside the $effect that calls load(),
    // and stop()'s onChange reads discussedSync, which would make that effect
    // re-run whenever load() sets it.
    const loadId = id;
    untrack(() => {
      discussedSync?.stop();
      discussedSync = null;
      discussedQuestions = [];
      discussedError = null;
      topicsSync?.stop();
      topicsSync = null;
      allTopics = [];
    });
    // Set when the stored draft should be re-saved as soon as the page is
    // loaded, without waiting for an edit — see below.
    let resaveDraft = false;
    try {
      const [identity, mk, anketa] = await Promise.all([
        ensureUnlocked(),
        loadMasterKey(),
        apiGet<AnketaDetail>(`/api/anketas/${id}`, { signal: readAbort }),
      ]);
      if (!mk) throw new Error($_('anketa.errorNotLoggedIn'));

      detail = anketa;
      // Both run alongside the rest of load(), and neither fails it.
      if (anketa.templateKey === 'custom') {
        void loadCustomQuestions(anketa);
      } else {
        customQuestions = { status: 'builtin' };
      }
      companyTemplates = [];
      if (anketa.archivedAt === null && !anketa.oneOff) {
        void loadCompanyTemplates(anketa.id);
      }
      draftKey = await deriveDraftKey(identity.privateKey);
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
        if (isOpenPastPeriod(anketa, defaultNext)) {
          // Left open for longer than its period (GitHub issue #204): back on
          // the pair's cadence, not a full period from whenever it's closed.
          nextMeetingDate = nextCadenceDate(
            anketa.meetingDate,
            periodicityDays,
            defaultNext,
          );
        } else {
          defaultNext.setDate(defaultNext.getDate() + periodicityDays);
          nextMeetingDate = defaultNext.toISOString().slice(0, 10);
        }
      }
      nextTemplateDefault = defaultNextChoice(anketa);
      nextTemplateChoice = nextTemplateDefault;

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

      // Bound to this anketa's id and key, like the discussed sync below.
      const topicsAnketaId = anketa.id;
      const topics: TopicsSync = new TopicsSync({
        items: await decryptTopics(anketa.topicsBlob, key),
        version: anketa.topicsVersion,
        save: async (items, expectedVersion) => {
          const blob = await encryptBlob(items, key);
          const result = await apiPut<{ topicsVersion: number }>(
            `/api/anketas/${topicsAnketaId}/topics`,
            { blob, expectedVersion },
          );
          return result.topicsVersion;
        },
        decrypt: (blob) => decryptTopics(blob, key),
        onChange: (items) => {
          if (topicsSync === topics) allTopics = items;
        },
        onArchived: () => {
          if (topicsSync === topics) enterArchivedState();
        },
      });
      if (id === loadId) {
        topicsSync?.stop();
        topicsSync = topics;
        allTopics = topics.items();
        if (anketa.archivedAt !== null) topics.stop();
      }

      goals = anketa.goals;
      if (anketa.goalCheckpointsBlob) {
        const envelope = await decryptBlob<GoalCheckpoint[]>(
          anketa.goalCheckpointsBlob,
          key,
        );
        allCheckpoints = envelope.data;
      }

      const discussedItems = await decryptDiscussed(anketa.discussedBlob, key);
      // Bound to this anketa's id and key: a save still in flight after the
      // page moves to another anketa can only ever write to this one.
      const discussedAnketaId = anketa.id;
      const sync: DiscussedSync = new DiscussedSync({
        items: discussedItems,
        version: anketa.discussedVersion,
        save: async (items, expectedVersion) => {
          const blob = await encryptBlob(encodeDiscussed(items), key);
          const result = await apiPut<{ discussedVersion: number }>(
            `/api/anketas/${discussedAnketaId}/discussed`,
            { blob, expectedVersion },
          );
          return result.discussedVersion;
        },
        decrypt: (blob) => decryptDiscussed(blob, key),
        onChange: (view) => {
          if (discussedSync !== sync) return;
          discussedQuestions = view.discussed;
          discussedError = view.error;
        },
        onArchived: () => {
          if (discussedSync === sync) enterArchivedState();
        },
        genericError: () => $_('anketa.errorUpdateDiscussed'),
      });
      // A slower load() of a previously opened anketa must not bind its
      // sync to the page now showing another one; and two overlapping loads
      // of the same anketa must not leave two syncs saving at once.
      if (id === loadId) {
        discussedSync?.stop();
        discussedSync = sync;
        discussedQuestions = sync.view().discussed;
        if (anketa.archivedAt !== null) sync.stop();
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
      if (myBlob && myPublished) {
        const envelope = await decryptBlob<Answers>(myBlob, key);
        myAnswers = envelope.data;
      } else if (myBlob) {
        // Undecryptable (GitHub issue #129) — start from an empty draft with a
        // notice instead of failing the whole page.
        const draft = await decryptDraft(myBlob, draftKey, mk);
        myAnswers = draft?.answers ?? {};
        draftUnreadable = draft === null;
        // Stored under the master key from before drafts moved off it —
        // re-saved under the draft key, so a later password change can't
        // strand it.
        resaveDraft = draft?.legacy === true;
      }
      if (!myPublished) {
        // A present local backup is always at least as fresh as the last
        // confirmed server save (written on every edit, not debounced) —
        // safe to prefer unconditionally. See anketa/draftBackup.ts.
        const localBackup = await loadDraftBackup(id, draftKey, mk);
        if (localBackup) {
          myAnswers = localBackup.answers;
          // A backup that rescues an unreadable server draft is written back
          // straight away too — otherwise it's lost with this tab.
          resaveDraft ||= localBackup.legacy || draftUnreadable;
          draftUnreadable = false;
        }
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
      followEmailLink();
      // Not on an archived anketa: the server refuses draft saves there. (A
      // same-instance switch to another anketa id mid-load is unreachable today
      // — see docs/decisions/2026-09-10-comment-thread-reuse-state-deferred.md.)
      if (resaveDraft && !archived) scheduleSave();
    } catch (error) {
      if (isAbortError(error)) return;
      loadError =
        error instanceof ApiError ? error.message : $_('anketa.errorLoad');
    }
  }

  async function loadCustomQuestions(anketa: AnketaDetail): Promise<void> {
    customQuestions = { status: 'loading' };
    let questions: QuestionSet | null = null;
    try {
      if (anketa.customTemplateVersionId !== null) {
        const version = await fetchTemplateVersion(
          anketa.customTemplateVersionId,
          readAbort,
        );
        questions = questionSetFromDefinition(
          version.definition,
          anketa.formVersion,
        );
      }
    } catch (error) {
      if (isAbortError(error)) return;
    }
    // Another anketa may have been opened in this page meanwhile.
    if (detail?.id !== anketa.id) return;
    customQuestions =
      questions === null
        ? { status: 'failed' }
        : { status: 'ready', questions };
    followEmailLink();
  }

  /**
   * The follow-up email's two links (GitHub issue #202) land on the archive
   * form or on the date field that moves the meeting. Not before this anketa
   * is loaded with everything above the form in place, a company template's
   * questions included, or the form would be pushed back down. Also called on
   * hashchange: the link may open in a tab already showing this meeting.
   */
  function followEmailLink(): void {
    const hash = window.location.hash;
    if (hash !== FOLLOW_UP_HASH.close && hash !== FOLLOW_UP_HASH.reschedule) {
      return;
    }
    if (!loaded || detail?.id !== id || customQuestions.status === 'loading') {
      return;
    }
    clearFollowUpHash();
    if (hash === FOLLOW_UP_HASH.reschedule) header?.focusRescheduleDate();
    else void tick().then(goToArchiveSection);
  }

  /**
   * Retry swaps its banner for a loading line, so focus goes back to Retry if
   * the questions fail again, or to my side's heading once they're there
   * (#149, #151). Not if the user has moved on meanwhile.
   */
  async function retryCustomQuestions(): Promise<void> {
    if (!detail) return;
    const started = beginAction();
    await loadCustomQuestions(detail);
    void refocus(
      pageMain,
      customQuestions.status === 'failed'
        ? '[data-action="retry-questions"]'
        : '[data-my-side-heading]',
      { startedOn: started },
    );
  }

  /** The archive picker's company templates; built-ins only if they can't be loaded. */
  async function loadCompanyTemplates(anketaId: string): Promise<void> {
    let templates: CompanyTemplate[] = [];
    try {
      templates = await fetchCompanyTemplates(readAbort);
    } catch {
      // Not a load error: the picker still has the built-ins and its default.
    }
    if (detail?.id === anketaId) companyTemplates = templates;
  }

  /** The questions of one side, built-in or from the company template. */
  function questionsFor(side: Side): Question[] {
    if (!detail) return [];
    if (detail.templateKey !== 'custom') {
      return getQuestionsForSide(side, detail.formVersion, detail.templateKey);
    }
    return customQuestions.status === 'ready'
      ? customQuestions.questions[side]
      : [];
  }

  /**
   * After a 422 `template_unavailable` from archive (#133 §7.4): the chosen
   * template was archived meanwhile. Refreshes only the picker's default,
   * choice and list, not the whole page, which would drop the chosen date and
   * any edit in progress. The next click archives with the new default.
   */
  async function refreshNextTemplate(): Promise<void> {
    if (!detail) return;
    const anketaId = detail.id;
    const [fresh] = await Promise.all([
      apiGet<AnketaDetail>(`/api/anketas/${anketaId}`),
      loadCompanyTemplates(anketaId),
    ]);
    if (detail?.id !== anketaId) return;
    detail = {
      ...detail,
      nextCycleTemplateKey: fresh.nextCycleTemplateKey,
      nextCustomTemplateId: fresh.nextCustomTemplateId,
    };
    nextTemplateDefault = defaultNextChoice(fresh);
    nextTemplateChoice = nextTemplateDefault;
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

    // Only ever false -> true. Archiving is one-way and happens once on the
    // server (AnketaRepository::markArchivedIfOpen(); the demo reset deletes
    // and recreates anketas rather than un-archiving one), so a response
    // saying "not archived" to a page that knows otherwise is simply older —
    // e.g. a tick sent just before the archive committed, landing after
    // handleArchive() already learned about it (GitHub issue #130 review).
    // Applying it would bring the Archive button back. `missed` is only ever
    // set together with archivedAt and never changes afterwards, so it's
    // applied only from a response that has archivedAt.
    const archivedChanged = live.archivedAt !== null && !archived;
    const missedChanged = live.archivedAt !== null && live.missed !== missed;
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
    const topicsChanged =
      topicsSync !== null && live.topicsVersion > topicsSync.version;
    // `>`: a live-state read that left before this tab's own save landed can
    // be older than the version that save already confirmed.
    const discussedChanged =
      discussedSync !== null && live.discussedVersion > discussedSync.version;
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
      !topicsChanged &&
      !checkpointsChanged &&
      !discussedChanged
    ) {
      return;
    }

    // Scalars/banners: nothing on this page edits them inline, so always apply
    // immediately regardless of any other in-progress local action.
    if (archivedChanged) enterArchivedState();
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
    // The same for the topics: an open edit form there is no reason to wait,
    // since it keeps its text while the list around it changes.
    const pollTopicsSync = topicsSync;
    const willApplyTopics =
      topicsChanged && pollTopicsSync !== null && !pollTopicsSync.busy;
    const willApplyMyBlob = myBlobChanged && !wasEditingMyAnswers;
    // DiscussedSync.applyRemote() re-checks busy and the version itself, after
    // the awaits below, so the snapshot here only saves a pointless fetch.
    const pollDiscussedSync = discussedSync;
    const willApplyDiscussed =
      discussedChanged && pollDiscussedSync !== null && !pollDiscussedSync.busy;

    const needsFullDetail =
      counterpartPublishedChanged ||
      counterpartBlobChanged ||
      willApplyMyBlob ||
      willApplyComments ||
      willApplyOutcomes ||
      willApplyTopics ||
      willApplyCheckpoints ||
      willApplyDiscussed;
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

    if (willApplyTopics && pollTopicsSync === topicsSync) {
      const decrypted = await decryptTopics(fresh.topicsBlob, anketaKey);
      pollTopicsSync?.applyRemote(decrypted, fresh.topicsVersion);
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

    if (willApplyDiscussed && pollDiscussedSync === discussedSync) {
      const decrypted = await decryptDiscussed(fresh.discussedBlob, anketaKey);
      pollDiscussedSync?.applyRemote(decrypted, fresh.discussedVersion);
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
      // No busy gate: the counterpart's own answers are never edited from
      // this session. One accepted loss (GitHub issue #131 §4.5): emptying
      // an answer with no comments yet hides that field in the collapsed
      // view, unmounting its CommentThread along with any unsent first
      // comment typed there.
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
    if (!loaded || myPublished || !draftKey) return;
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
    if (!draftKey) return;
    try {
      const blob = await encryptBlob(myAnswers, draftKey);
      await apiPut(`/api/anketas/${id}/draft`, { blob });
      saveState = 'saved';
    } catch {
      saveState = 'error';
    }
  }

  /**
   * What both confirmations of closing the meeting call (GitHub issue #229):
   * my unpublished answers are published first, and the meeting stays open
   * if that fails, so closing never strands them in a draft.
   */
  async function closeMeeting(missedFlag: boolean): Promise<void> {
    if (!detail) return;
    // Checked before anything is published: a cleared or mistyped date is
    // no reason to publish and then fail to close.
    if (!skipNextMeeting && !detail.oneOff && !nextMeetingDate) {
      actionError = $_('anketa.errorNextMeetingDate');
      return;
    }
    closingMeeting = true;
    try {
      if (closeConfirmation.publishFirst) {
        const closingId = id;
        const published = await handlePublish();
        // Not published (its error is shown), or the page has opened
        // another anketa meanwhile.
        if (id !== closingId || !published) return;
      }
      await handleArchive(missedFlag);
    } finally {
      closingMeeting = false;
    }
  }

  /** Whether this call published my side. */
  async function handlePublish(): Promise<boolean> {
    if (!anketaKey) return false;
    publishing = true;
    actionError = null;
    try {
      clearTimeout(saveTimer);
      const blob = await encryptBlob(myAnswers, anketaKey);
      await apiPost(`/api/anketas/${id}/publish`, { blob });
      myPublished = true;
      clearDraftBackup(id);
      return true;
    } catch (error) {
      if (error instanceof ApiError && error.status === 409) {
        // My side is already published (in another tab, or by a request
        // whose response was lost), or the meeting is archived. Nothing
        // here learns the former by itself (the live-state poll leaves my
        // own side alone), so every retry would be refused the same way:
        // say what to do. Not reloaded for them: that would drop what is
        // typed here and the archive form's choices.
        actionError = $_('anketa.publishConflict');
      } else {
        actionError =
          error instanceof ApiError ? error.message : $_('anketa.errorPublish');
      }
      return false;
    } finally {
      publishing = false;
    }
  }

  /**
   * Which controls started an answers-edit action (GitHub issue #166): the
   * card's top (the header's Edit, then the sticky edit bar) or its bottom,
   * pressed or used with Ctrl+S / ⌘S, or Ctrl+S / ⌘S elsewhere in my side:
   * in answer field `fieldId` of question `block`, or outside any field
   * (`side`, in `block` if any). Each action swaps out the button it was pressed on, so
   * focus moves to the control that replaces it in the same place (#149,
   * #151): Edit to Cancel, Save and Cancel to Edit, a failed Save back to
   * Save. The top's targets may be far from where the user was (the bar
   * follows them down the card, the header's Edit doesn't), so the page only
   * scrolls there for a keyboard press (`focusOptions`). A save from a field
   * goes to its question's heading, since the field is swapped out while
   * the save is in flight (see refocusAfterFieldSave()). Once archived, no
   * Edit is left, so my side's heading.
   */
  type AnswersEditTrigger =
    | { from: 'top' | 'bottom'; focusOptions: FocusOptions }
    | { from: 'field'; block: HTMLElement | null; fieldId: string }
    | { from: 'side'; block: HTMLElement | null };

  function clickedAt(
    from: 'top' | 'bottom',
    click: MouseEvent,
  ): AnswersEditTrigger {
    return { from, focusOptions: fallbackFocusOptions(click) };
  }

  /**
   * The button an action lands on. At the top, Edit is in the card's header
   * and Save/Cancel in the sticky bar: `data-answers-edit` tells them apart.
   */
  function answersEditButton(
    from: 'top' | 'bottom',
    action: 'edit-answers' | 'save-answers' | 'cancel-answers',
  ): string {
    const region =
      from === 'bottom'
        ? 'bottom'
        : action === 'edit-answers'
          ? 'header'
          : 'bar';
    return `[data-answers-edit="${region}"] [data-action="${action}"]`;
  }

  /** `failed`: a save that left the edit open. */
  function focusAfterAnswersEdit(
    trigger: AnswersEditTrigger,
    action: 'edit-answers' | 'save-answers' | 'cancel-answers',
    {
      startedOn,
      failed = false,
    }: { startedOn?: ActionStart; failed?: boolean } = {},
  ): void {
    if (archived) {
      void refocus(pageMain, '[data-my-side-heading]', { startedOn });
    } else if (trigger.from === 'field') {
      void refocusAfterShortcutSave(
        trigger.block,
        failed ? trigger.fieldId : null,
        startedOn,
      );
    } else if (trigger.from === 'side') {
      void refocusAfterShortcutSave(trigger.block, null, startedOn);
    } else {
      void refocus(pageMain, answersEditButton(trigger.from, action), {
        startedOn,
        focusOptions: trigger.focusOptions,
      });
    }
  }

  /**
   * After a Ctrl+S save started in my side outside its edit controls: the
   * question's heading, or, if the save failed in answer field
   * `failedFieldId`, back into it when it's a free-text answer's textarea,
   * where Ctrl+S is usually pressed. Other fields don't say which of their
   * controls had focus, so the heading.
   */
  async function refocusAfterShortcutSave(
    block: HTMLElement | null,
    failedFieldId: string | null,
    startedOn?: ActionStart,
  ): Promise<void> {
    if (!block) {
      await refocus(pageMain, '[data-my-side-heading]', { startedOn });
      return;
    }
    // Once the field is back from its read-only form.
    await tick();
    const field =
      failedFieldId === null
        ? undefined
        : findRow(block, 'data-field-id', failedFieldId);
    if (field?.querySelector('textarea')) {
      await refocus(field, 'textarea', { startedOn });
    } else {
      await refocus(block, 'h4', { startedOn });
    }
  }

  function startEditingAnswers(trigger: AnswersEditTrigger): void {
    // A plain copy, not the $state proxy, so nothing typed in the edit reaches it.
    answersBeforeEdit = $state.snapshot(myAnswers);
    editingMyAnswers = true;
    actionError = null;
    focusAfterAnswersEdit(trigger, 'cancel-answers');
  }

  function cancelEditingAnswers(trigger: AnswersEditTrigger): void {
    if (answersBeforeEdit) myAnswers = answersBeforeEdit;
    answersBeforeEdit = null;
    editingMyAnswers = false;
    actionError = null;
    focusAfterAnswersEdit(trigger, 'edit-answers');
  }

  let mySideCard = $state<HTMLElement>();
  /** Bumped by every load(), so an answers save can tell its response is from before it. */
  let loadGeneration = 0;

  /**
   * Ctrl+S / ⌘S in my side (see saveShortcutPlace()) saves the edit instead
   * of opening the browser's "Save page" dialog. A held shortcut's repeats
   * do nothing anywhere on the page, edit or not, so one that outlasts the
   * save doesn't open that dialog either.
   */
  function handleMySideKeydown(event: KeyboardEvent): void {
    if (!isSaveShortcut(event)) return;
    if (event.repeat) {
      event.preventDefault();
      return;
    }
    if (!editingMyAnswers) return;
    const place = saveShortcutPlace(event.target, mySideCard);
    if (!place) return;
    event.preventDefault();
    if (savingAnswersEdit || anyEntryEditOpen || place.at === 'unadded-entry') {
      return;
    }
    let trigger: AnswersEditTrigger;
    if (place.at === 'field') {
      trigger = { from: 'field', block: place.block, fieldId: place.fieldId };
    } else if (place.at === 'side') {
      trigger = { from: 'side', block: place.block };
    } else {
      trigger = { from: place.at, focusOptions: {} };
    }
    handleSaveAnswersEdit(trigger).catch((error: unknown) => {
      console.error(error);
    });
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
   * The one way this page moves to archived — from the live-state poll,
   * handleArchive()'s success and 409 branches, and handleSaveAnswersEdit()'s
   * 409 — so the transition can't drift between them. One-way: see
   * pollLiveStateFor()'s archivedChanged.
   */
  function enterArchivedState(): void {
    archived = true;
    exitAnswersEditSession();
    discussedSync?.stop();
    topicsSync?.stop();
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
  async function handleSaveAnswersEdit(
    trigger: AnswersEditTrigger,
  ): Promise<void> {
    if (!anketaKey) return;
    const anketaId = id;
    // load() resets the edit when another anketa opens, or this one again,
    // and a new edit may have started since: the response is then stale.
    // Counted per load, since the id alone misses A → B → A.
    const generation = loadGeneration;
    const isSuperseded = () => loadGeneration !== generation;
    // What Cancel would restore, read now: an archive during the awaits clears it.
    const publishedBeforeEdit = answersBeforeEdit;
    const expectedVersion = myBlobVersion;
    const started = beginAction();
    savingAnswersEdit = true;
    actionError = null;
    try {
      const blob = await encryptBlob(myAnswers, anketaKey);
      // Not sent at all for an edit the page has already dropped.
      if (isSuperseded()) return;
      const result = await apiPut<{ blobVersion: number }>(
        `/api/anketas/${anketaId}/answers`,
        { blob, expectedVersion },
      );
      if (isSuperseded()) return;
      myBlobVersion = result.blobVersion;
      answersBeforeEdit = null;
      editingMyAnswers = false;
    } catch (error) {
      if (isSuperseded()) return;
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
          let saved: Answers | null = null;
          if (anketaKey && typeof conflict.blob === 'string') {
            try {
              saved = (await decryptBlob<Answers>(conflict.blob, anketaKey))
                .data;
            } catch (decryptError) {
              console.error(decryptError);
            }
          }
          if (isSuperseded()) return;
          // Without readable saved content, what was published when this
          // edit started, never this tab's unsent edit shown as published.
          // The version is taken either way, or the live-state poll would
          // re-fetch it on every tick.
          myAnswers = saved ?? publishedBeforeEdit ?? myAnswers;
          myBlobVersion = conflict.blobVersion;
          exitAnswersEditSession();
        } else {
          enterArchivedState();
        }
        actionError = error.message;
      } else {
        actionError =
          error instanceof ApiError
            ? error.message
            : $_('anketa.errorSaveAnswers');
      }
    } finally {
      // A superseded save's flag was reset by load(), and may be a newer save's now.
      if (!isSuperseded()) savingAnswersEdit = false;
    }
    // Back to Edit once the session is over (saved, or ended by a 409),
    // to Save if it failed and is still open.
    focusAfterAnswersEdit(
      trigger,
      editingMyAnswers ? 'save-answers' : 'edit-answers',
      { startedOn: started, failed: editingMyAnswers },
    );
  }

  /**
   * Auto-recreation (Phase 6d) is triggered from right here, not a server-side
   * background job — this browser already has the current anketa's key unsealed, so
   * it generates and seals the *next* anketa's key itself (same dance as
   * CreateAnketa.svelte) and sends the sealed keys along with the archive request.
   * The server never generates or even transiently holds an anketa key.
   *
   * Called through closeMeeting() from both AnketaHeader (the "not closed" card's
   * "Didn't happen" button, always with `missedFlag: true`) and AnketaArchiveSection
   * (the regular archive button, always with `missedFlag: false`), each after its
   * own confirmation, via the same `onArchive` callback prop —
   * `skipNextMeeting`/`nextMeetingDate` stay page-level state (bound down into
   * AnketaArchiveSection for editing) precisely so this function keeps reading
   * whatever's currently set in that form regardless of which button triggered it,
   * matching the behavior before either component existed. The same goes for
   * `nextTemplateChoice` (GitHub issue #140): "cancel as missed" deliberately
   * creates the successor type the archive form currently shows.
   *
   * A one-off anketa (GitHub issue #111) never gets a successor — the server
   * forces that regardless of the request — so it's sent as an explicit skip,
   * with no next key to generate; the form hides the "skip" checkbox for it.
   */
  async function handleArchive(
    missedFlag: boolean,
    topicsRetries = 0,
  ): Promise<void> {
    if (!detail) return;
    archiving = true;
    actionError = null;
    const archiveId = id;
    try {
      // Save the last "discussed" ticks first: archiving freezes them. If
      // that fails, its banner explains why; Archive again goes ahead. The
      // wait can be long enough for the page to open another anketa.
      const saved = (await discussedSync?.settled()) ?? true;
      // And any topic save: the carry-forward below reads the list it leaves.
      const topicsSaved = (await topicsSync?.settled()) ?? true;
      if (id !== archiveId) return;
      if (!saved || !topicsSaved) {
        // Archived meanwhile (the save got the archived 409), or the save
        // failed, whose own banner says why: either way this click didn't
        // archive, so say so.
        actionError = archived
          ? $_('anketa.alreadyArchivedElsewhere')
          : $_('anketa.errorArchive');
        return;
      }
      let body: Record<string, unknown> = { missed: missedFlag };

      if (skipNextMeeting || detail.oneOff) {
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
        // this page first loaded.
        const outcomesBlobNext = await carryForwardOutcomeItems(
          allOutcomes,
          nextKey,
        );
        // The topics not yet discussed (GitHub issue #206), with the
        // version of the list they're taken from: if the counterpart has
        // changed it since (the list here can be a poll interval behind),
        // the server refuses the archive with the current list, and the
        // catch below archives again with that. This tab's own topic saves
        // are already in (settled() above).
        const topicsBlobNext = topicsSync
          ? await carryForwardTopicItems(topicsSync.items(), nextKey)
          : undefined;
        const topicsVersion = topicsSync?.version;

        body = {
          ...body,
          nextMeetingDate: new Date(nextMeetingDate).toISOString(),
          // Sent only when changed from the default, so an untouched form
          // sends exactly the old request and the server applies its own
          // current default.
          ...nextTemplateFields(nextTemplateChoice, nextTemplateDefault),
          mySealedKey: mySealedKeyNext,
          counterpartSealedKey: counterpartSealedKeyNext,
          ...(outcomesBlobNext ? { outcomesBlob: outcomesBlobNext } : {}),
          ...(topicsBlobNext ? { topicsBlob: topicsBlobNext } : {}),
          ...(topicsVersion === undefined ? {} : { topicsVersion }),
        };
      }

      await apiPost(`/api/anketas/${id}/archive`, body);
      missed = missedFlag;
      enterArchivedState();
    } catch (error) {
      // The page has opened another anketa meanwhile: nothing here is its.
      if (id !== archiveId) return;
      const newerTopics = versionConflictBody(
        error,
        'topicsBlob',
        'topicsVersion',
      );
      if (newerTopics && anketaKey && topicsSync && topicsRetries < 3) {
        // The topics changed after the carry-forward was built: take the
        // list the refusal carries and archive again. Still not archived, so
        // this click's choices stand.
        const sync = topicsSync;
        try {
          const items = await decryptTopics(newerTopics.blob, anketaKey);
          if (sync !== topicsSync) return;
          sync.applyRemote(items, newerTopics.version);
        } catch (decryptError) {
          console.error(decryptError);
          actionError = $_('anketa.errorArchive');
          return;
        }
        // Handles its own errors, like this call.
        await handleArchive(missedFlag, topicsRetries + 1);
        return;
      }
      const alreadyArchived =
        error instanceof ApiError && error.status === 409
          ? (error.body as {
              archivedAt?: string | null;
              missed?: boolean;
            } | null)
          : null;
      if (alreadyArchived && typeof alreadyArchived.archivedAt === 'string') {
        // Already archived — by the counterpart, another tab, or an earlier
        // attempt of this one whose response was lost (GitHub issue #130).
        // The anketa is archived either way, but this click's choices
        // (missed, skip/next meeting date) may not be what got applied, so
        // say so instead of looking like a success. The 409 carries the
        // state that did get applied; `missed` comes from there, not from
        // missedFlag.
        missed = alreadyArchived.missed === true;
        enterArchivedState();
        actionError = $_('anketa.alreadyArchivedElsewhere');
      } else if (
        error instanceof ApiError &&
        (error.body as { code?: string } | null)?.code ===
          'template_unavailable'
      ) {
        actionError = error.message;
        try {
          await refreshNextTemplate();
        } catch {
          // The error is already shown; the next click tries again.
        }
      } else {
        actionError =
          error instanceof ApiError ? error.message : $_('anketa.errorArchive');
      }
    } finally {
      archiving = false;
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
   * Shared reapply-on-conflict update for the anketa's optimistic-
   * concurrency list blobs (comments, outcomes, goal checkpoints — see
   * blobSync.ts):
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
   *
   * Bound to the anketa and key it started with: if the page has opened
   * another anketa by the time a request returns, nothing is sent to or
   * applied on that one, and the caller gets undefined.
   */
  async function updateField<T>(
    blobKey: 'commentsBlob' | 'outcomesBlob' | 'goalCheckpointsBlob',
    versionKey:
      'commentsVersion' | 'outcomesVersion' | 'goalCheckpointsVersion',
    endpoint: string,
    apply: (current: T) => T,
  ): Promise<{ items: T; version: number } | undefined> {
    const key = anketaKey;
    const anketaId = id;
    if (!key) return undefined;
    const fresh = await apiGet<AnketaDetail>(`/api/anketas/${anketaId}`);
    let savedVersion = fresh[versionKey];
    const items = await updateBlobWithRetry<T>(
      key,
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
          `/api/anketas/${anketaId}/${endpoint}`,
          { blob, expectedVersion },
        );
        savedVersion = result[versionKey] ?? savedVersion;
      },
      (error) => {
        // Not the "archived" 409, which carries no version to retry against.
        return versionConflictBody(error, blobKey, versionKey) ?? undefined;
      },
    );
    if (id !== anketaId) return undefined;
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

  // Reactive autosave: fires whenever myAnswers changes (property-level mutations from AnswerField included).
  $effect(() => {
    void JSON.stringify(myAnswers);
    // untrack: this effect only reacts to myAnswers — clearing the flag here, or
    // load() resetting it, mustn't re-run it.
    if (untrack(() => draftUnreadable)) {
      if (!hasAnyAnswer(myAnswers)) return;
      draftUnreadable = false;
    }
    scheduleSave();
    // Local backup, written immediately (not debounced like the server sync) —
    // protects against a silent server-sync failure within the debounce
    // window, not just its timing. See anketa/draftBackup.ts.
    if (loaded && !myPublished && draftKey) {
      // saveDraftBackup() has no internal try/catch (e.g. sessionStorage quota),
      // so unlike the other fire-and-forget calls in this file, this one can
      // genuinely reject — .catch() here isn't just future-proofing.
      saveDraftBackup(id, myAnswers, draftKey).catch((error: unknown) => {
        console.error(error);
      });
    }
  });
</script>

<!-- Save and Cancel of an answers edit, in the sticky bar and at the card's
     bottom alike. -->
{#snippet answersEditButtons(place: 'top' | 'bottom')}
  <button
    type="button"
    class="btn {answersEditUnsaved ? 'btn-primary' : 'btn-secondary'}"
    data-action="save-answers"
    aria-keyshortcuts="Control+S Meta+S"
    onclick={(click) => handleSaveAnswersEdit(clickedAt(place, click))}
    disabled={savingAnswersEdit || anyEntryEditOpen}
  >
    {savingAnswersEdit ? $_('anketa.saving') : $_('anketa.save')}
  </button>
  <button
    type="button"
    class="btn btn-ghost"
    data-action="cancel-answers"
    onclick={(click) => cancelEditingAnswers(clickedAt(place, click))}
    disabled={savingAnswersEdit || anyEntryEditOpen}
  >
    {$_('anketa.cancel')}
  </button>
{/snippet}

<!-- On the document rather than my side's <section>, which as a static
     element mustn't take a key handler (a11y_no_static_element_interactions). -->
<svelte:document onkeydown={handleMySideKeydown} />
<svelte:window onhashchange={followEmailLink} />

<!-- With the anketa loaded, the page is three blocks: the header, the notes
     panel and the rest. Narrow, they stack in that order; from 840px the notes
     become a sticky column on the right (GitHub issue #132 §6.1). -->
<main
  bind:this={pageMain}
  class:with-notes={!loadError && detail}
  class:notes-hidden={notesHidden}
>
  {#if loadError}
    <p role="alert" class="banner-error">{loadError}</p>
  {:else if !detail}
    <p class="text-muted">{$_('anketa.loading')}</p>
  {:else}
    <div class="anketa-top">
      {#if isJustCreated(id)}
        <!-- A button, not a link: a plain link reloads the page, and the
             settings are handed to the form in memory (GitHub issue #198). -->
        <p class="banner-success created-notice">
          <span>{$_('anketa.createdNotice')}</span>
          <button
            type="button"
            class="btn btn-secondary"
            onclick={() => {
              startCreateAnother(id);
              navigate('/anketas/new');
            }}>{$_('anketa.createAnother')}</button
          >
        </p>
      {/if}
      <AnketaHeader
        bind:this={header}
        {id}
        counterpartId={detail.counterpartId}
        counterpartDeleted={detail.counterpartDeleted}
        counterpartName={detail.counterpartName}
        counterpartEmail={detail.counterpartEmail}
        meetingDate={detail.meetingDate}
        templateName={detail.customTemplateName}
        {archived}
        {missed}
        oneOff={detail.oneOff}
        archiving={closingMeeting}
        {publishing}
        answersEditOpen={closeBlockedByEdit}
        confirmation={closeConfirmation}
        bind:actionError
        onArchive={closeMeeting}
        onRescheduled={(meetingDate) => {
          if (detail) detail = { ...detail, meetingDate };
        }}
      />
    </div>

    <!-- Keyed by id: this page is reused across anketa ids, and each anketa's
         notes panel must be a fresh instance whose old one really unmounts
         (GitHub issue #132 §6.3). -->
    {#key id}
      <!-- Only once `detail` is this anketa's: it keeps the previous
           anketa's value until the new one loads, and the panel's privacy
           line names the counterpart. -->
      {#if detail.id === id}
        <PrivateNotes
          anketaId={id}
          counterpartName={detail.counterpartName}
          counterpartEmail={detail.counterpartEmail}
          counterpartDeleted={detail.counterpartDeleted}
          bind:hidden={notesHidden}
        />
      {/if}
    {/key}

    <div class="anketa-main">
      <!-- Above both sides: the first thing seen on opening the meeting.
           Keyed by id, like the notes panel: a topic typed but not added
           mustn't follow the page to another meeting. -->
      {#key id}
        {#if topicsSync}
          {@const sync = topicsSync}
          <AnketaTopics
            items={allTopics}
            {myUserId}
            {authorNames}
            {archived}
            locked={archiving}
            oneOff={detail.oneOff}
            updateTopics={(apply) => sync.update(apply)}
          />
        {/if}
      {/key}

      {#if customQuestions.status === 'failed'}
        <section class="card">
          <p role="alert" class="banner-error">
            {$_('anketa.questionsUnavailable')}
          </p>
          <button
            type="button"
            class="btn btn-secondary"
            data-action="retry-questions"
            onclick={retryCustomQuestions}
          >
            {$_('anketa.retry')}
          </button>
        </section>
      {:else if customQuestions.status === 'loading'}
        <p class="text-muted">{$_('anketa.loadingQuestions')}</p>
      {:else}
        <!-- My side -->
        <!-- ignoreHeldEnter: Edit, Save and Cancel each move focus to the
             button replacing them, which a held Enter would press next. -->
        <section
          class="card side-card"
          bind:this={mySideCard}
          onkeydowncapture={ignoreHeldEnter}
        >
          <div class="heading-row" data-answers-edit="header">
            <h2 tabindex="-1" data-my-side-heading>
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
            <LockIcon encrypted />
            {#if myPublished && !archived && !editingMyAnswers}
              <!-- Also at the card's bottom: either way, no scrolling the
                   whole card just to start an edit (GitHub issue #166). -->
              <button
                type="button"
                class="btn btn-ghost heading-edit-btn"
                data-action="edit-answers"
                onclick={(click) =>
                  startEditingAnswers(clickedAt('top', click))}
                disabled={archiving}
              >
                {$_('anketa.editAnswers')}
              </button>
            {/if}
          </div>

          {#if editingMyAnswers}
            <!-- Sticks to the top of the viewport while the card scrolls
                 past, so Save is never a scroll away and the open edit,
                 which is only saved by Save, stays visible (GitHub issue
                 #166). The bottom's Save/Cancel stay too. -->
            <div class="answers-edit-bar" data-answers-edit="bar">
              <p class="answers-edit-status">
                <span>{$_('anketa.editingAnswers')}</span>
                <!-- Not a live region: it would be announced on nearly
                     every keystroke into an empty or restored field. -->
                {#if answersEditUnsaved}
                  <span class="text-muted answers-edit-unsaved"
                    >{$_('anketa.unsavedChanges')}</span
                  >
                {/if}
              </p>
              <div class="answers-edit-buttons">
                {@render answersEditButtons('top')}
              </div>
            </div>
          {/if}

          {#if !myPublished && !archived}
            <!-- Nothing requires an answer, but a page of empty blocks reads as
                 a mandatory essay (GitHub issue #199). -->
            <p class="text-muted optional-hint">
              {$_('anketa.allFieldsOptional')}
            </p>
          {/if}

          {#if draftUnreadable && !myPublished && !archived}
            <p role="alert" class="banner-error">
              {$_('anketa.draftUnreadable')}
            </p>
          {/if}

          <div class="blocks">
            {#each questionsFor(detail.myRole) as question (question.id)}
              <AnswerBlock
                {question}
                bind:answers={myAnswers}
                readonly={mySideReadonly}
                collapsed={mySideCollapsed}
                showComments={myPublished}
                bind:fieldsWithOpenEntryEdit
                bind:commentThreadsBusy
                {commentsByTarget}
                {authorNames}
                {myUserId}
                {recentlyArrivedCommentIds}
                {submitComment}
                onEditComment={handleEditComment}
                onDeleteComment={handleDeleteComment}
                anketaId={id}
                isDiscussed={discussedQuestions.includes(question.id)}
                onToggleDiscussed={myPublished && discussedSync
                  ? () => discussedSync?.toggle(question.id)
                  : undefined}
                {archived}
                disabled={archived || archiving}
              />
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
              onclick={() => void handlePublish()}
              disabled={publishing || anyEntryEditOpen}
            >
              {publishing ? $_('anketa.publishing') : $_('anketa.publish')}
            </button>
          {:else if archived}
            <span class="tag tag-accent side-publish-btn"
              >{$_('anketa.badgePublished')}</span
            >
          {:else if editingMyAnswers}
            <div class="answers-edit-actions" data-answers-edit="bottom">
              {@render answersEditButtons('bottom')}
            </div>
          {:else}
            <div class="answers-edit-actions" data-answers-edit="bottom">
              <span class="tag tag-accent side-publish-btn"
                >{$_('anketa.badgePublished')}</span
              >
              <button
                type="button"
                class="btn btn-ghost"
                data-action="edit-answers"
                onclick={(click) =>
                  startEditingAnswers(clickedAt('bottom', click))}
                disabled={archiving}
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
            <LockIcon encrypted />
          </div>
          {#if !counterpartAnswers}
            <p class="text-muted">{$_('anketa.notPublishedYet')}</p>
          {:else if counterpartSide}
            <div class="blocks">
              {#each questionsFor(counterpartSide) as question (question.id)}
                <AnswerBlock
                  {question}
                  bind:answers={counterpartAnswers}
                  readonly
                  collapsed
                  showComments
                  bind:commentThreadsBusy
                  {commentsByTarget}
                  {authorNames}
                  {myUserId}
                  {recentlyArrivedCommentIds}
                  {submitComment}
                  onEditComment={handleEditComment}
                  onDeleteComment={handleDeleteComment}
                  anketaId={id}
                  isDiscussed={discussedQuestions.includes(question.id)}
                  onToggleDiscussed={discussedSync
                    ? () => discussedSync?.toggle(question.id)
                    : undefined}
                  {archived}
                  disabled={archived || archiving}
                />
              {/each}
            </div>
          {/if}
        </section>
      {/if}

      <AnketaOutcomes
        items={allOutcomes}
        {myUserId}
        {allComments}
        {authorNames}
        bind:commentThreadsBusy
        {recentlyArrivedCommentIds}
        bind:addingOutcome
        bind:editingOutcomeId
        bind:confirmingDeleteOutcomeId
        {anotherOutcomeActionOpen}
        bind:actionError
        {submitComment}
        onEditComment={handleEditComment}
        onDeleteComment={handleDeleteComment}
        {updateOutcomes}
      />

      <AnketaGoals
        {id}
        bind:goals
        {allCheckpoints}
        {allComments}
        {myUserId}
        {authorNames}
        bind:commentThreadsBusy
        {recentlyArrivedCommentIds}
        bind:addingCheckpoint
        bind:actionError
        {submitComment}
        onEditComment={handleEditComment}
        onDeleteComment={handleDeleteComment}
        {updateGoalCheckpoints}
      />

      {#if actionError}
        <p role="alert" class="banner-error">{actionError}</p>
      {/if}
      {#if discussedError}
        <p role="alert" class="banner-error">{discussedError}</p>
      {/if}

      {#if !archived}
        <AnketaArchiveSection
          anketaId={id}
          archiving={closingMeeting}
          {publishing}
          answersEditOpen={closeBlockedByEdit}
          confirmation={closeConfirmation}
          counterpartName={shortDisplayName(
            detail.counterpartName,
            detail.counterpartEmail,
          )}
          oneOff={detail.oneOff}
          bind:skipNextMeeting
          bind:nextMeetingDate
          bind:nextTemplateChoice
          defaultChoice={nextTemplateDefault}
          {companyTemplates}
          templateRetired={detail.templateKey === 'custom' &&
            detail.nextCycleTemplateKey !== 'custom'}
          onArchive={closeMeeting}
        />
      {/if}
    </div>
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

  .created-notice {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px 12px;
    margin: 0;
  }

  /* Below the breakpoint these keep main's own column and gap, so the page
     looks as it did before the notes column. */
  .anketa-top,
  .anketa-main {
    display: flex;
    flex-direction: column;
    gap: 20px;
  }

  /* The column sizes are §6.1's, with the form's maximum at 46rem - 48px,
     its width before the column (main's 46rem includes its padding). The
     breakpoint is 840px, not 820px: main's 24px gutters (the design assumed
     16px) put the grid's minimum at 30rem + 16rem + 24px + 48px = 808px, and a
     classic 17px scrollbar needs 825px. It's in em, which media queries
     resolve against the browser's font size, as the rem columns do: with a
     larger default font the columns grow and so does the breakpoint.
     PrivateNotes.svelte switches its own column styles at the same width. */
  @media (min-width: 52.5em) {
    main.with-notes {
      display: grid;
      grid-template-columns: minmax(30rem, calc(46rem - 48px)) minmax(
          16rem,
          22rem
        );
      /* The content's row takes whatever height the notes column needs
         beyond it, so the header's row never stretches. */
      grid-template-rows: auto 1fr;
      column-gap: 24px;
      row-gap: 20px;
      justify-content: center;
      max-width: calc(68rem + 24px);
    }

    /* Hidden notes are a narrow rail. The page owns this state, so the
       columns stay put while no panel is mounted between anketas. */
    main.with-notes.notes-hidden {
      grid-template-columns: minmax(30rem, calc(46rem - 48px)) auto;
    }

    .anketa-top,
    .anketa-main {
      grid-column: 1;
    }

    main.with-notes > :global(.private-notes) {
      grid-column: 2;
      grid-row: 1 / -1;
      align-self: start;
      position: sticky;
      top: 16px;
    }
  }

  .blocks {
    display: flex;
    flex-direction: column;
  }

  .optional-hint {
    font-size: 13px;
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

  /* Keyboard focus scrolled to the top doesn't land under the sticky edit
     bar: one row of it, or two once it wraps on a narrow screen. */
  :global(html:has(.answers-edit-bar)) {
    scroll-padding-top: 4rem;
  }

  @media (max-width: 30em) {
    :global(html:has(.answers-edit-bar)) {
      scroll-padding-top: 6.5rem;
    }
  }

  .heading-edit-btn {
    margin-left: auto;
  }

  /* The card has no overflow clipping, so this sticks within it. It spans
     the card's padding so the answers scrolling under it don't show at its
     sides, and the discussed blocks' opacity puts them in their own stacking
     context, hence the z-index. */
  .answers-edit-bar {
    position: sticky;
    top: 0;
    z-index: 1;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px 12px;
    margin: 0 calc(-1 * var(--space-3));
    padding: 8px var(--space-3);
    background: var(--color-surface);
    border-bottom: 1px solid var(--color-divider);
  }

  .answers-edit-status {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 10px;
    margin: 0;
    font-size: 13px;
    font-weight: 600;
  }

  .answers-edit-unsaved {
    font-weight: 400;
  }

  .answers-edit-buttons {
    display: flex;
    gap: 10px;
  }
</style>
