<script lang="ts">
  import { onMount, tick } from 'svelte';
  import { _ } from 'svelte-i18n';
  import { apiPut, ApiError } from '../api/client';
  import { formatDisplayDate } from '../datePreference.svelte';
  import DateInput from '../design/DateInput.svelte';
  import { goToArchiveSection } from './archiveHeading';
  import {
    clearFollowUpHash,
    FOLLOW_UP_HASH,
    RESCHEDULE_DATE_ID,
  } from './followUpLinks';
  import { isOverdue as computeIsOverdue } from './isOverdue';
  import { shortDisplayName } from '../userDisplay';

  let {
    id,
    counterpartName,
    counterpartEmail,
    meetingDate,
    templateName,
    archived,
    missed,
    oneOff,
    archiving,
    answersEditOpen,
    actionError = $bindable<string | null>(),
    onArchive,
    onRescheduled,
  }: {
    id: string;
    counterpartName: string;
    counterpartEmail: string;
    meetingDate: string;
    /** A custom anketa's company template name (GitHub issue #144); null otherwise. */
    templateName: string | null;
    archived: boolean;
    missed: boolean;
    /** A one-off has no next meeting to schedule, so the "close" action says only that. */
    oneOff: boolean;
    archiving: boolean;
    /**
     * See AnketaArchiveSection's prop of the same name. The same hint is
     * shown next to this button as next to that one: a page can show both,
     * but each explains the disabled button beside it.
     */
    answersEditOpen: boolean;
    actionError: string | null;
    onArchive: (missed: boolean) => Promise<void>;
    onRescheduled: (meetingDate: string) => void;
  } = $props();

  // `archived` (not `detail.archivedAt`, which Anketa.svelte's
  // handleArchive/pollLiveStateFor update but never write back to
  // `detail.archivedAt` itself) is the fresher source of truth here, which
  // is exactly why isOverdue.ts's own `isOverdue()` takes `archived: boolean`
  // rather than an `archivedAt` timestamp — only its nullness ever mattered.
  const isOverdue = $derived(computeIsOverdue({ archived, meetingDate }));

  let rescheduleDate = $state('');
  let rescheduling = $state(false);
  let showReschedule = $state(false);

  // The follow-up email's "move it to another date" link (GitHub issue #202)
  // lands on the date field: the "not closed" card's, or, for a meeting moved
  // to a later day since the email, the one behind "Change date". Also on
  // hashchange: the link may open in a tab already showing this meeting.
  function followRescheduleLink(): void {
    if (window.location.hash !== FOLLOW_UP_HASH.reschedule) return;
    clearFollowUpHash();
    showReschedule = true;
    void tick().then(() =>
      document.getElementById(RESCHEDULE_DATE_ID)?.focus(),
    );
  }
  onMount(followRescheduleLink);

  async function handleReschedule(): Promise<void> {
    if (!rescheduleDate) return;
    rescheduling = true;
    actionError = null;
    try {
      const isoDate = new Date(rescheduleDate).toISOString();
      // Reads the server's own DATE_ATOM-formatted value back from the
      // response rather than reusing the client's isoDate string for
      // meetingDate — the two formats differ (ISO-with-millis vs.
      // DATE_ATOM), and live-state's own meetingDate always comes back in
      // the server's format, so comparing against a client-formatted string
      // here would never match, causing the poll's meetingDateChanged to
      // spuriously fire on the very next tick. (No separate applied*
      // tracker for this one, unlike the version counters — meetingDate is
      // compared directly against detail.meetingDate, which onRescheduled
      // is the single source of truth for.)
      const result = await apiPut<{ meetingDate: string }>(
        `/api/anketas/${id}/meeting-date`,
        { meetingDate: isoDate },
      );
      onRescheduled(result.meetingDate);
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
</script>

<svelte:window onhashchange={followRescheduleLink} />

<h1>
  {$_('anketa.titleWithCounterpart', {
    values: {
      name: shortDisplayName(counterpartName, counterpartEmail),
    },
  })}
</h1>
<p class="meta">
  <span class="text-muted"
    >{$_('anketa.meetingLabel')}
    {formatDisplayDate(meetingDate)}</span
  >
  {#if templateName}<span class="text-muted template-name"
      >· {templateName}</span
    >{/if}
  {#if archived}<span class="tag tag-neutral">{$_('anketa.badgeArchived')}</span
    >{/if}
  {#if missed}<span class="tag tag-neutral">{$_('anketa.badgeMissed')}</span
    >{/if}
  {#if isOverdue}<span class="tag tag-neutral"
      >{$_('anketa.badgeNotClosed')}</span
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
    <DateInput
      id={RESCHEDULE_DATE_ID}
      bind:value={rescheduleDate}
      disabled={rescheduling}
    />
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
    <strong
      >{$_('anketa.notClosedHeading', {
        values: { date: formatDisplayDate(meetingDate) },
      })}</strong
    >
    <!-- Only leads to the archive form further down the page (GitHub issue
         #201): closing a meeting has options of its own, so it isn't
         duplicated here. -->
    <button
      type="button"
      class="btn btn-secondary go-to-archive-btn"
      onclick={goToArchiveSection}
    >
      {oneOff ? $_('anketa.closeOneOff') : $_('anketa.closeAndScheduleNext')}
    </button>
    <div class="reschedule-row">
      <DateInput
        id={RESCHEDULE_DATE_ID}
        bind:value={rescheduleDate}
        disabled={rescheduling}
      />
      <button
        type="button"
        class="btn btn-secondary"
        onclick={handleReschedule}
        disabled={rescheduling || !rescheduleDate}
      >
        {rescheduling ? $_('anketa.rescheduling') : $_('anketa.reschedule')}
      </button>
    </div>
    <button
      type="button"
      class="btn btn-ghost cancel-missed-btn"
      onclick={() => onArchive(true)}
      disabled={archiving || answersEditOpen}
      aria-describedby={answersEditOpen
        ? 'cancel-missed-after-edit-hint'
        : undefined}
    >
      {archiving ? $_('anketa.cancelling') : $_('anketa.didNotHappen')}
    </button>
    {#if answersEditOpen}
      <p id="cancel-missed-after-edit-hint" class="text-muted overdue-note">
        {$_('anketa.archiveAfterAnswersEdit')}
      </p>
    {/if}
  </div>
{/if}

<style>
  h1 {
    font-size: 26px;
    margin-bottom: 4px;
  }

  .template-name {
    overflow-wrap: anywhere;
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
    gap: 10px;
  }

  .go-to-archive-btn {
    align-self: flex-start;
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
</style>
