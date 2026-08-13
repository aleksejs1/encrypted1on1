<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet, ApiError } from '../api/client';
  import { decryptBlob, unsealAnketaKey } from '../crypto/anketaKey';
  import { ensureUnlocked } from '../crypto/identity';
  import { aggregateReport, dateRangeForQuarterPreset, type DecryptedAnketaForReport, type ReportData } from '../anketa/report';
  import type { Answers } from '../anketa/questions';
  import type { Goal, GoalCheckpoint } from '../anketa/goals';

  interface AnketaBulkRow {
    id: string;
    myRole: 'employee' | 'manager';
    counterpartId: string;
    counterpartEmail: string;
    meetingDate: string;
    archivedAt: string | null;
    mySealedKey: string;
    employeeBlob: string | null;
    employeePublishedAt: string | null;
    managerBlob: string | null;
    managerPublishedAt: string | null;
    goals: Goal[];
    goalCheckpointsBlob: string | null;
  }

  let anketas = $state<AnketaBulkRow[]>([]);
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
  const GOAL_STATUS_TAG_CLASSES: Record<Goal['status'], string> = {
    in_progress: 'tag-accent',
    achieved: 'tag-accent-2',
    cancelled: 'tag-neutral',
  };

  const CHECKPOINT_STATUS_TAG_KEYS: Record<string, string> = {
    on_track: 'anketa.statusTagOnTrack',
    at_risk: 'anketa.statusTagAtRisk',
    blocked: 'anketa.statusTagBlocked',
  };
  const CHECKPOINT_STATUS_TAG_CLASSES: Record<string, string> = {
    on_track: 'tag-accent-2',
    at_risk: 'tag-outline',
    blocked: 'tag-neutral',
  };

  const managerTargets = $derived(
    [...new Map(anketas.filter((a) => a.myRole === 'manager').map((a) => [a.counterpartId, a.counterpartEmail]))].map(
      ([id, email]) => ({ id, email }),
    ),
  );

  $effect(() => {
    apiGet<AnketaBulkRow[]>('/api/anketas/bulk')
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
      for (const detail of matching) {
        // A wrong-key AEAD failure here means this anketa was sealed under a keypair
        // that no longer matches identity.privateKey (most likely a password reset
        // since this anketa was created, see ResetPassword.svelte) — skip just this
        // one anketa rather than failing the whole report over a single unreadable
        // entry in a potentially long list.
        let anketaKey: Uint8Array;
        try {
          anketaKey = await unsealAnketaKey(detail.mySealedKey, identity.publicKey, identity.privateKey);
        } catch {
          continue;
        }

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
          anketaId: detail.id,
          meetingDate: detail.meetingDate,
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
    <p class="banner-error">{loadError}</p>
  {:else}
    <form class="card filters" onsubmit={handleGenerate}>
      <div class="field target-field">
        <label for="report-for">{$_('report.reportForLabel')}</label>
        <select id="report-for" class="input" bind:value={target}>
          <option value="me">{$_('report.me')}</option>
          {#each managerTargets as person (person.id)}
            <option value={person.id}>{person.email}</option>
          {/each}
        </select>
      </div>

      <fieldset>
        <legend>{$_('report.dateRangeLegend')}</legend>
        <button type="button" class="btn btn-secondary" onclick={applyQuarterPreset}>{$_('report.quarterPreset')}</button>
        <div class="range-row">
          <div class="field">
            <label for="range-from">{$_('report.fromLabel')}</label>
            <input id="range-from" class="input" type="date" bind:value={rangeStart} />
          </div>
          <div class="field">
            <label for="range-to">{$_('report.toLabel')}</label>
            <input id="range-to" class="input" type="date" bind:value={rangeEnd} />
          </div>
        </div>
      </fieldset>

      {#if generateError}
        <p class="banner-error">{generateError}</p>
      {/if}

      <button type="submit" class="btn btn-primary btn-block" disabled={generating || !rangeStart || !rangeEnd}>
        {generating ? $_('report.generating') : $_('report.generate')}
      </button>
    </form>

    {#if report}
      <div class="results">
        <section class="card">
          <h3>{$_('report.achievementsHeading')}</h3>
          {#if report.achievements.length === 0}
            <p class="text-muted">{$_('report.nothingInRange')}</p>
          {:else}
            <ul>
              {#each report.achievements as entry (entry.id)}
                <li>{entry.text} <span class="text-muted entry-date">— {entry.date}</span></li>
              {/each}
            </ul>
          {/if}
        </section>

        <section class="card">
          <h3>{$_('report.growthHeading')}</h3>
          {#if report.growth.length === 0}
            <p class="text-muted">{$_('report.nothingInRange')}</p>
          {:else}
            <ul>
              {#each report.growth as entry (entry.id)}
                <li>{entry.text} <span class="text-muted entry-date">— {entry.date}</span></li>
              {/each}
            </ul>
          {/if}
        </section>

        <section class="card">
          <h3>{$_('report.goalsHeading')}</h3>
          {#if report.goals.length === 0}
            <p class="text-muted">{$_('report.noGoalsInRange')}</p>
          {:else}
            <div class="goals">
              {#each report.goals as goal (goal.goalUuid)}
                <div class="goal">
                  <div class="goal-header">
                    <strong>{goal.title}</strong>
                    <span class="tag {GOAL_STATUS_TAG_CLASSES[goal.status]}">{$_(GOAL_STATUS_KEYS[goal.status])}</span>
                  </div>
                  {#if goal.description}<p class="text-muted goal-description">{goal.description}</p>{/if}
                  {#if goal.checkpoints.length === 0}
                    <p class="text-muted">{$_('report.noCheckpointsInRange')}</p>
                  {:else}
                    <ul class="checkpoints">
                      {#each goal.checkpoints as checkpoint (checkpoint.id)}
                        <li>
                          <span class="text-muted checkpoint-date">{new Date(checkpoint.createdAt).toLocaleDateString()}</span>
                          {#if checkpoint.text}<span>{checkpoint.text}</span>{/if}
                          {#if checkpoint.statusTag}<span class="tag {CHECKPOINT_STATUS_TAG_CLASSES[checkpoint.statusTag]}">{$_(CHECKPOINT_STATUS_TAG_KEYS[checkpoint.statusTag])}</span>{/if}
                        </li>
                      {/each}
                    </ul>
                  {/if}
                </div>
              {/each}
            </div>
          {/if}
        </section>
      </div>
    {/if}
  {/if}
</main>

<style>
  main {
    max-width: 48rem;
    margin: 0 auto;
    padding: 32px 24px 60px;
  }

  h1 {
    font-size: 28px;
    margin-bottom: 20px;
  }

  .filters {
    gap: 16px;
    margin-bottom: 20px;
  }

  .target-field {
    max-width: 280px;
  }

  fieldset {
    border: none;
    padding: 0;
    margin: 0;
  }

  fieldset legend {
    font-size: 13px;
    font-family: var(--font-heading);
    font-weight: var(--font-heading-weight);
    margin-bottom: 8px;
  }

  .range-row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 10px;
  }

  .range-row .field {
    flex: 1;
    min-width: 160px;
  }

  .results {
    display: flex;
    flex-direction: column;
    gap: 20px;
  }

  h3 {
    margin: 0 0 4px;
  }

  ul {
    margin: 0;
    padding-left: 18px;
    font-size: 14px;
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .entry-date {
    font-size: 11px;
  }

  .goals {
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  .goal {
    border-top: 1px solid var(--color-divider);
    padding-top: 12px;
  }

  .goal:first-child {
    border-top: none;
    padding-top: 0;
  }

  .goal-header {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    font-size: 14px;
  }

  .goal-description {
    margin: 4px 0 0;
  }

  .checkpoints {
    list-style: none;
    padding: 0;
    margin-top: 8px;
  }

  .checkpoints li {
    display: flex;
    gap: 8px;
    align-items: center;
    font-size: 13px;
  }

  .checkpoint-date {
    font-size: 11px;
    width: 70px;
    flex: none;
  }
</style>
