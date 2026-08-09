<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet, ApiError } from '../api/client';
  import { decryptBlob, unsealAnketaKey } from '../crypto/anketaKey';
  import { ensureUnlocked } from '../crypto/identity';
  import { aggregateReport, dateRangeForQuarterPreset, type DecryptedAnketaForReport, type ReportData } from '../anketa/report';
  import type { Answers } from '../anketa/questions';
  import type { Goal, GoalCheckpoint } from '../anketa/goals';

  interface AnketaSummary {
    id: string;
    myRole: 'employee' | 'manager';
    counterpartId: string;
    counterpartEmail: string;
    meetingDate: string;
    archivedAt: string | null;
  }

  interface AnketaDetailForReport {
    mySealedKey: string;
    employeeBlob: string | null;
    employeePublishedAt: string | null;
    managerBlob: string | null;
    managerPublishedAt: string | null;
    goals: Goal[];
    goalCheckpointsBlob: string | null;
  }

  let anketas = $state<AnketaSummary[]>([]);
  let loadError = $state<string | null>(null);

  /** "me" or a counterpartId from an anketa where I was the manager — see the Phase 6f plan for why those are the only two valid targets. */
  let target = $state('me');
  let rangeStart = $state('');
  let rangeEnd = $state('');
  let generating = $state(false);
  let generateError = $state<string | null>(null);
  let report = $state<ReportData | null>(null);

  const GOAL_STATUS_KEYS: Record<Goal['status'], string> = {
    in_progress: 'anketa.goalStatusInProgress',
    achieved: 'anketa.goalStatusAchieved',
    cancelled: 'anketa.goalStatusCancelled',
  };

  const CHECKPOINT_STATUS_TAG_KEYS: Record<string, string> = {
    on_track: 'anketa.statusTagOnTrack',
    at_risk: 'anketa.statusTagAtRisk',
    blocked: 'anketa.statusTagBlocked',
  };

  const managerTargets = $derived(
    [...new Map(anketas.filter((a) => a.myRole === 'manager').map((a) => [a.counterpartId, a.counterpartEmail]))].map(
      ([id, email]) => ({ id, email }),
    ),
  );

  $effect(() => {
    apiGet<AnketaSummary[]>('/api/anketas')
      .then((list) => {
        anketas = list;
      })
      .catch((error: unknown) => {
        loadError = error instanceof ApiError ? error.message : $_('report.errorLoad');
      });
    applyQuarterPreset();
  });

  function applyQuarterPreset(): void {
    const { start, end } = dateRangeForQuarterPreset();
    rangeStart = start.toISOString().slice(0, 10);
    rangeEnd = end.toISOString().slice(0, 10);
  }

  async function handleGenerate(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (!rangeStart || !rangeEnd) return;

    generating = true;
    generateError = null;
    report = null;
    try {
      const identity = await ensureUnlocked();
      const start = new Date(rangeStart);
      const end = new Date(rangeEnd);
      end.setHours(23, 59, 59, 999);

      const matching = anketas.filter((a) => {
        if (a.archivedAt === null) return false;
        const meetingDate = new Date(a.meetingDate);
        if (meetingDate < start || meetingDate > end) return false;
        return target === 'me' ? a.myRole === 'employee' : a.myRole === 'manager' && a.counterpartId === target;
      });

      const decrypted: DecryptedAnketaForReport[] = [];
      for (const summary of matching) {
        const detail = await apiGet<AnketaDetailForReport>(`/api/anketas/${summary.id}`);
        const anketaKey = await unsealAnketaKey(detail.mySealedKey, identity.publicKey, identity.privateKey);

        const employeeAnswers =
          detail.employeePublishedAt && detail.employeeBlob
            ? (await decryptBlob<Answers>(detail.employeeBlob, anketaKey)).data
            : null;
        const managerAnswers =
          detail.managerPublishedAt && detail.managerBlob
            ? (await decryptBlob<Answers>(detail.managerBlob, anketaKey)).data
            : null;
        const checkpoints = detail.goalCheckpointsBlob
          ? (await decryptBlob<GoalCheckpoint[]>(detail.goalCheckpointsBlob, anketaKey)).data
          : [];

        decrypted.push({
          anketaId: summary.id,
          meetingDate: summary.meetingDate,
          employeeAnswers,
          managerAnswers,
          goals: detail.goals,
          checkpoints,
        });
      }

      report = aggregateReport(decrypted);
    } catch (error) {
      generateError = error instanceof ApiError ? error.message : $_('report.errorGenerate');
    } finally {
      generating = false;
    }
  }
</script>

<main>
  <h1>{$_('report.title')}</h1>

  {#if loadError}
    <p class="error">{loadError}</p>
  {:else}
    <form onsubmit={handleGenerate}>
      <label>
        {$_('report.reportForLabel')}
        <select bind:value={target}>
          <option value="me">{$_('report.me')}</option>
          {#each managerTargets as person (person.id)}
            <option value={person.id}>{person.email}</option>
          {/each}
        </select>
      </label>

      <fieldset>
        <legend>{$_('report.dateRangeLegend')}</legend>
        <button type="button" onclick={applyQuarterPreset}>{$_('report.quarterPreset')}</button>
        <label>
          {$_('report.fromLabel')}
          <input type="date" bind:value={rangeStart} />
        </label>
        <label>
          {$_('report.toLabel')}
          <input type="date" bind:value={rangeEnd} />
        </label>
      </fieldset>

      {#if generateError}
        <p class="error">{generateError}</p>
      {/if}

      <button type="submit" disabled={generating || !rangeStart || !rangeEnd}>
        {generating ? $_('report.generating') : $_('report.generate')}
      </button>
    </form>

    {#if report}
      <section>
        <h2>{$_('report.achievementsHeading')}</h2>
        {#if report.achievements.length === 0}
          <p>{$_('report.nothingInRange')}</p>
        {:else}
          <ul>
            {#each report.achievements as entry (entry.id)}
              <li>{entry.date} — {entry.text}</li>
            {/each}
          </ul>
        {/if}
      </section>

      <section>
        <h2>{$_('report.growthHeading')}</h2>
        {#if report.growth.length === 0}
          <p>{$_('report.nothingInRange')}</p>
        {:else}
          <ul>
            {#each report.growth as entry (entry.id)}
              <li>{entry.date} — {entry.text}</li>
            {/each}
          </ul>
        {/if}
      </section>

      <section>
        <h2>{$_('report.goalsHeading')}</h2>
        {#if report.goals.length === 0}
          <p>{$_('report.noGoalsInRange')}</p>
        {:else}
          {#each report.goals as goal (goal.goalUuid)}
            <fieldset>
              <legend>{goal.title} <span class="badge">{$_(GOAL_STATUS_KEYS[goal.status])}</span></legend>
              {#if goal.description}<p>{goal.description}</p>{/if}
              {#if goal.checkpoints.length === 0}
                <p>{$_('report.noCheckpointsInRange')}</p>
              {:else}
                <ul>
                  {#each goal.checkpoints as checkpoint (checkpoint.id)}
                    <li>
                      {new Date(checkpoint.createdAt).toLocaleDateString()}
                      {#if checkpoint.statusTag}<span class="badge">{$_(CHECKPOINT_STATUS_TAG_KEYS[checkpoint.statusTag])}</span>{/if}
                      {#if checkpoint.text}— {checkpoint.text}{/if}
                    </li>
                  {/each}
                </ul>
              {/if}
            </fieldset>
          {/each}
        {/if}
      </section>
    {/if}
  {/if}
</main>

<style>
  main {
    max-width: 40rem;
    margin: 4rem auto;
    padding: 0 1rem;
    font-family: system-ui, sans-serif;
  }

  form {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
  }

  label {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
  }

  fieldset {
    border: 1px solid #ddd;
    border-radius: 0.25rem;
    margin-bottom: 1rem;
  }

  .badge {
    margin-left: 0.5rem;
    font-size: 0.75rem;
    color: #6b6b6b;
  }

  .error {
    color: #c0392b;
  }
</style>
