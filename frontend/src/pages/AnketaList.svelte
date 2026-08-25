<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet, apiPut } from '../api/client';
  import type {
    AnketaDetail as AnketaDetailFull,
    AnketaSummary,
  } from '../api/types';
  import { formatDisplayDate } from '../datePreference.svelte';
  import { ensureUnlocked } from '../crypto/identity';
  import { fromBase64 } from '../crypto/encoding';
  import {
    decryptBlob,
    sealAnketaKey,
    unsealAnketaKey,
  } from '../crypto/anketaKey';
  import { groupByCounterpart } from '../anketa/groupByCounterpart';
  import { extractTrendValues } from '../anketa/moodWorkloadTrend';
  import TrendSparkline from '../anketa/TrendSparkline.svelte';
  import { QUESTIONS_BY_SIDE, type Answers } from '../anketa/questions';
  import { fullDisplayName } from '../userDisplay';

  type AnketaDetail = Pick<
    AnketaDetailFull,
    'mySealedKey' | 'counterpartPublicKey'
  >;

  interface BadgeMeta {
    cls: string;
    label: string;
  }

  interface BulkAnketaForTrend {
    counterpartId: string;
    counterpartEmail: string;
    counterpartName: string;
    meetingDate: string;
    archivedAt: string | null;
    employeeBlob: string | null;
    employeePublishedAt: string | null;
    mySealedKey: string;
  }

  interface TrendRow {
    counterpartId: string;
    counterpartEmail: string;
    counterpartName: string;
    meetingDate: string;
    moodNow: string | undefined;
    workloadNow: string | undefined;
  }

  // The mood/workload radio fields' own already-defined options *are* the
  // trend scale (private/init.txt: "график трендов по radio-полям") — no
  // separate chart library, per the same spec section (hand-rolled SVG only).
  const MOOD_OPTIONS = QUESTIONS_BY_SIDE.employee
    .find((q) => q.id === 'mood')!
    .fields.find((f) => f.id === 'moodNow')!.options!;
  const WORKLOAD_OPTIONS = QUESTIONS_BY_SIDE.employee
    .find((q) => q.id === 'workload')!
    .fields.find((f) => f.id === 'workloadNow')!.options!;

  function isOverdue(anketa: AnketaSummary): boolean {
    return (
      anketa.archivedAt === null &&
      new Date(anketa.meetingDate).getTime() < Date.now()
    );
  }

  /** The first full Unicode code point of a string, not just its first UTF-16 code unit — plain indexing/slicing would split an astral-plane character (e.g. an emoji) into a broken surrogate half. */
  function firstCodePoint(s: string): string {
    return [...s][0] ?? '';
  }

  /** One letter per word for a multi-word name ("Alex Morgan" -> "AM"), else the first two characters (an email with no name set, same as before this label could ever be a name). */
  function initials(label: string): string {
    const words = label.trim().split(/\s+/);
    return words.length >= 2
      ? (firstCodePoint(words[0]) + firstCodePoint(words[1])).toUpperCase()
      : [...label].slice(0, 2).join('').toUpperCase();
  }

  function counterpartLabel(
    name: string,
    email: string,
    deleted: boolean,
  ): string {
    return deleted
      ? $_('anketaList.deletedCounterpart')
      : fullDisplayName(name, email);
  }

  function badgesFor(anketa: AnketaSummary): BadgeMeta[] {
    const list: BadgeMeta[] = [];
    if (isOverdue(anketa))
      list.push({ cls: 'tag-outline', label: $_('anketaList.badgeOverdue') });
    if (anketa.archivedAt)
      list.push({ cls: 'tag-neutral', label: $_('anketaList.badgeArchived') });
    if (anketa.missed)
      list.push({ cls: 'tag-neutral', label: $_('anketaList.badgeMissed') });
    if (anketa.myPublishedAt)
      list.push({
        cls: 'tag-accent',
        label: $_('anketaList.badgePublishedByMe'),
      });
    if (anketa.counterpartPublishedAt) {
      list.push({
        cls: 'tag-accent-2',
        label: $_('anketaList.badgePublishedByCounterpart'),
      });
    }
    return list;
  }

  let anketas = $state(apiGet<AnketaSummary[]>('/api/anketas'));

  let groupBy = $state<'date' | 'counterpart'>('date');
  let resharing = $state(false);
  let reshareResult = $state<'success' | 'partial' | null>(null);

  // Lazy: only fetched/decrypted the first time the grouped view is actually
  // opened, not on every page load — most visits use the flat date view.
  let trendRows = $state<TrendRow[]>([]);
  let trendLoaded = false;

  $effect(() => {
    if (groupBy === 'counterpart') void loadTrendData();
  });

  async function loadTrendData(): Promise<void> {
    if (trendLoaded) return;
    trendLoaded = true;
    try {
      const identity = await ensureUnlocked();
      const bulk = await apiGet<BulkAnketaForTrend[]>('/api/anketas/bulk');
      const rows: TrendRow[] = [];

      for (const anketa of bulk) {
        if (
          anketa.archivedAt === null ||
          anketa.employeePublishedAt === null ||
          !anketa.employeeBlob
        )
          continue;

        // A wrong-key AEAD failure here means this anketa was sealed under a keypair
        // that no longer matches identity.privateKey (most likely a password reset),
        // same discipline as Report.svelte/AccountSettings.svelte — skip just this one.
        let anketaKey: Uint8Array;
        try {
          anketaKey = await unsealAnketaKey(
            anketa.mySealedKey,
            identity.publicKey,
            identity.privateKey,
          );
        } catch {
          continue;
        }

        const answers = (
          await decryptBlob<Answers>(anketa.employeeBlob, anketaKey)
        ).data;
        rows.push({
          counterpartId: anketa.counterpartId,
          counterpartEmail: anketa.counterpartEmail,
          counterpartName: anketa.counterpartName,
          meetingDate: anketa.meetingDate,
          moodNow:
            typeof answers.moodNow === 'string' ? answers.moodNow : undefined,
          workloadNow:
            typeof answers.workloadNow === 'string'
              ? answers.workloadNow
              : undefined,
        });
      }

      rows.sort(
        (a, b) =>
          new Date(a.meetingDate).getTime() - new Date(b.meetingDate).getTime(),
      );
      trendRows = rows;
    } catch {
      // A decorative enhancement on an already-working list — a failed
      // fetch/decrypt just means no sparklines, not a page-level error.
    }
  }

  const trendGroups = $derived(groupByCounterpart(trendRows));

  function trendFor(counterpartId: string): TrendRow[] {
    return (
      trendGroups.find((g) => g.counterpartId === counterpartId)?.anketas ?? []
    );
  }

  async function reshareOne(anketaId: string): Promise<void> {
    const identity = await ensureUnlocked();
    const detail = await apiGet<AnketaDetail>(`/api/anketas/${anketaId}`);
    const anketaKey = await unsealAnketaKey(
      detail.mySealedKey,
      identity.publicKey,
      identity.privateKey,
    );
    const sealedKey = await sealAnketaKey(
      anketaKey,
      await fromBase64(detail.counterpartPublicKey),
    );
    await apiPut(`/api/anketas/${anketaId}/reshare-key`, { sealedKey });
  }

  async function reshareAll(outdated: AnketaSummary[]): Promise<void> {
    resharing = true;
    reshareResult = null;
    let failures = 0;

    for (const anketa of outdated) {
      try {
        await reshareOne(anketa.id);
      } catch {
        // Keep going — one bad entry shouldn't block reshares that would otherwise succeed.
        failures++;
      }
    }

    reshareResult = failures === 0 ? 'success' : 'partial';
    resharing = false;
    anketas = apiGet<AnketaSummary[]>('/api/anketas');
  }
</script>

<main>
  <h1>{$_('anketaList.title')}</h1>

  {#snippet anketaRow(anketa: AnketaSummary)}
    <a href="/anketas/{anketa.id}" class="card elev-sm anketa-row">
      <div class="avatar">
        {initials(
          counterpartLabel(
            anketa.counterpartName,
            anketa.counterpartEmail,
            anketa.counterpartDeleted,
          ),
        )}
      </div>
      <div class="anketa-info">
        <div class="anketa-email">
          {counterpartLabel(
            anketa.counterpartName,
            anketa.counterpartEmail,
            anketa.counterpartDeleted,
          )}
        </div>
        <div class="text-muted anketa-meta">
          {$_(
            anketa.myRole === 'employee'
              ? 'common.roleEmployee'
              : 'common.roleManager',
          )} —
          {formatDisplayDate(anketa.meetingDate)}
        </div>
      </div>
      <div class="badges">
        {#each badgesFor(anketa) as badge (badge.cls + badge.label)}
          <span class="tag {badge.cls}">{badge.label}</span>
        {/each}
      </div>
    </a>
  {/snippet}

  {#await anketas}
    <p class="text-muted">{$_('common.loading')}</p>
  {:then list}
    {@const outdated = list.filter((anketa) => anketa.counterpartKeyOutdated)}
    {#if outdated.length > 0}
      <div class="card elev-sm banner-reshare">
        <p>{$_('anketaList.reshareBannerText')}</p>
        {#if reshareResult === 'success'}
          <p class="banner-success">{$_('anketaList.reshareSuccess')}</p>
        {:else if reshareResult === 'partial'}
          <p class="banner-error">{$_('anketaList.reshareFailure')}</p>
        {/if}
        <button
          type="button"
          class="btn btn-secondary"
          disabled={resharing}
          onclick={() => reshareAll(outdated)}
        >
          {resharing
            ? $_('anketaList.reshareInProgress')
            : $_('anketaList.reshareButton')}
        </button>
      </div>
    {/if}
    {#if list.length === 0}
      <div class="card elev-sm empty-state">
        <p class="text-muted">{$_('anketaList.empty')}</p>
        <a href="/anketas/new" class="btn btn-primary"
          >{$_('anketaList.newAnketa')}</a
        >
      </div>
    {:else}
      <div class="seg view-toggle">
        <label class="seg-opt">
          <input type="radio" name="view" bind:group={groupBy} value="date" />
          {$_('anketaList.viewToggleDate')}
        </label>
        <label class="seg-opt">
          <input
            type="radio"
            name="view"
            bind:group={groupBy}
            value="counterpart"
          />
          {$_('anketaList.viewToggleCounterpart')}
        </label>
      </div>

      {#if groupBy === 'counterpart'}
        <div class="groups">
          {#each groupByCounterpart(list) as group (group.counterpartId)}
            {@const groupDeleted =
              group.anketas[0]?.counterpartDeleted ?? false}
            {@const groupTrend = trendFor(group.counterpartId)}
            {@const moodValues = extractTrendValues(
              groupTrend.map((t) => ({ value: t.moodNow })),
              MOOD_OPTIONS,
            )}
            {@const workloadValues = extractTrendValues(
              groupTrend.map((t) => ({ value: t.workloadNow })),
              WORKLOAD_OPTIONS,
            )}
            <div class="card group-card">
              <div class="group-header">
                <div class="avatar avatar-accent-2">
                  {initials(
                    counterpartLabel(
                      group.counterpartName,
                      group.counterpartEmail,
                      groupDeleted,
                    ),
                  )}
                </div>
                <div class="anketa-info">
                  <div class="anketa-email">
                    {counterpartLabel(
                      group.counterpartName,
                      group.counterpartEmail,
                      groupDeleted,
                    )}
                  </div>
                  <div class="text-muted anketa-meta">
                    {$_('anketaList.nextMeetingLabel', {
                      values: {
                        date: formatDisplayDate(group.anketas[0].meetingDate),
                      },
                    })}
                  </div>
                </div>
                <div class="trend-sparklines">
                  <TrendSparkline
                    values={moodValues}
                    maxIndex={MOOD_OPTIONS.length - 1}
                    label={$_('anketaList.moodTrendLabel')}
                  />
                  <TrendSparkline
                    values={workloadValues}
                    maxIndex={WORKLOAD_OPTIONS.length - 1}
                    label={$_('anketaList.workloadTrendLabel')}
                  />
                </div>
              </div>
              <div class="group-anketas">
                {#each group.anketas as anketa (anketa.id)}
                  <a href="/anketas/{anketa.id}" class="group-anketa-row">
                    <span class="group-anketa-date"
                      >{formatDisplayDate(anketa.meetingDate)}</span
                    >
                    <div class="badges">
                      {#each badgesFor(anketa) as badge (badge.cls + badge.label)}
                        <span class="tag {badge.cls}">{badge.label}</span>
                      {/each}
                    </div>
                  </a>
                {/each}
              </div>
            </div>
          {/each}
        </div>
      {:else}
        <div class="flat-list">
          {#each list as anketa (anketa.id)}
            {@render anketaRow(anketa)}
          {/each}
        </div>
      {/if}
    {/if}
  {:catch error}
    <p class="banner-error">{error.message}</p>
  {/await}
</main>

<style>
  main {
    max-width: 52rem;
    margin: 0 auto;
    padding: 28px 24px 60px;
  }

  h1 {
    font-size: 30px;
    margin-bottom: 20px;
  }

  .banner-reshare {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 20px;
  }

  .view-toggle {
    margin-bottom: 20px;
  }

  .flat-list,
  .groups {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .anketa-row {
    flex-direction: row;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    text-decoration: none;
    color: inherit;
  }

  .avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: var(--color-accent-100);
    color: var(--color-accent-800);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--font-heading);
    font-size: 14px;
    flex: none;
  }

  .avatar-accent-2 {
    background: var(--color-accent-2-100);
    color: var(--color-accent-2-800);
  }

  .anketa-info {
    flex: 1;
    min-width: 160px;
  }

  .anketa-email {
    font-size: 14px;
  }

  .anketa-meta {
    font-size: 12px;
  }

  .badges {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
  }

  .group-card {
    padding: 0;
    overflow: hidden;
  }

  .group-header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    flex-wrap: wrap;
  }

  .trend-sparklines {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: none;
  }

  .group-anketas {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 0 16px 14px;
  }

  .group-anketa-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    border-radius: var(--radius-sm);
    background: var(--color-bg);
    text-decoration: none;
    color: inherit;
    flex-wrap: wrap;
  }

  .group-anketa-date {
    font-size: 13px;
    flex: 1;
    min-width: 100px;
  }

  .empty-state {
    align-items: center;
    text-align: center;
    padding: 48px 24px;
  }
</style>
