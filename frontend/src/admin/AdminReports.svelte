<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet, ApiError } from '../api/client';
  import { formatDisplayMonth } from '../datePreference.svelte';
  import { formatDate } from '../dateFormat';
  import { dateRangeForQuarterPreset } from '../anketa/report';
  import DateInput from '../design/DateInput.svelte';
  import BarChart from '../report/BarChart.svelte';
  import AdminTabStrip from './AdminTabStrip.svelte';
  import AdminGate from './AdminGate.svelte';

  /**
   * Mirrors OverviewAggregator::aggregate()'s return shape
   * (backend/src/Report/OverviewAggregator.php) — see
   * private/company-admin-reporting-proposal.md §7.1 for the field-by-field
   * reasoning (most notably: goals.totalInProgress/overdueInProgress and
   * users.* are deliberately *not* range-filtered, unlike everything else
   * on this page).
   */
  interface OverviewReport {
    meetings: {
      total: number;
      completed: number;
      missed: number;
      overdueOpen: number;
      upcomingOpen: number;
      responseRate: number;
    };
    goals: {
      createdInRange: number;
      achievedInRange: number;
      totalInProgress: number;
      overdueInProgress: number;
    };
    users: { active: number; blocked: number; admins: number };
    trend: {
      month: string;
      meetingsCompleted: number;
      goalsAchieved: number;
    }[];
  }

  /** Mirrors GoalsAggregator::aggregate()'s return shape (backend/src/Report/GoalsAggregator.php) — the dedicated Goals tab, proposal §7.2/§8.2. */
  interface GoalsReport {
    createdInRange: number;
    achievedInRange: number;
    cancelledInRange: number;
    totalInProgress: number;
    overdueInProgress: number;
    byMonth: { month: string; achieved: number }[];
  }

  const REPORT_TABS = [
    { key: 'overview', labelKey: 'adminReports.overviewTab' },
    { key: 'goals', labelKey: 'adminReports.goalsTab' },
  ] as const;
  type ReportTabKey = (typeof REPORT_TABS)[number]['key'];

  let rangeStart = $state('');
  let rangeEnd = $state('');
  let report = $state<OverviewReport | null>(null);
  let goalsReport = $state<GoalsReport | null>(null);
  // Set whenever the requested range changes and this tab hasn't been re-fetched for
  // it yet — the Goals endpoint is its own separate, unfiltered-goal-history query
  // (see AdminReportController::fetchGoalSnapshots()'s own docblock), so fetching it
  // eagerly alongside Overview on every "Generate report" click would double that
  // query for an admin who never opens this tab. Fetched lazily instead, the first
  // time the Goals tab is actually opened for a given range.
  let goalsReportStale = $state(true);
  let activeReportTab = $state<ReportTabKey>('overview');
  let loading = $state(false);
  let reportError = $state<string | null>(null);
  let goalsLoading = $state(false);
  let goalsError = $state<string | null>(null);
  // Bumped on every loadGoalsReport() call and captured as `requestId` — used to
  // ignore a response from a superseded request (e.g. two overlapping "Generate
  // report" clicks while on the Goals tab) so an older range's numbers can never
  // land after a newer request's, no matter which resolves first.
  let goalsRequestSequence = 0;

  async function loadInitialReport(): Promise<void> {
    applyQuarterPreset();
    await loadReport();
  }

  function applyQuarterPreset(): void {
    const { start, end } = dateRangeForQuarterPreset();
    // formatDate(..., 'iso'), not start.toISOString().slice(0, 10): the latter reads
    // the UTC calendar day, which is a day behind the viewer's own "today" for part of
    // every day in a positive-UTC-offset timezone — formatDate's date-object branch
    // uses the local getters instead (see its own docblock).
    rangeStart = formatDate(start, 'iso');
    rangeEnd = formatDate(end, 'iso');
  }

  async function loadReport(): Promise<void> {
    if (!rangeStart || !rangeEnd) return;

    loading = true;
    reportError = null;
    goalsReportStale = true;
    // Fired concurrently with the overview fetch below, not awaited in sequence —
    // the two endpoints are independent, and an admin sitting on the Goals tab
    // shouldn't pay overviewLatency + goalsLatency on every "Generate report" click.
    const goalsReload =
      activeReportTab === 'goals' ? loadGoalsReport() : Promise.resolve();
    try {
      const query = new URLSearchParams({ from: rangeStart, to: rangeEnd });
      report = await apiGet<OverviewReport>(
        `/api/admin/reports/overview?${query}`,
      );
    } catch (error) {
      reportError =
        error instanceof ApiError
          ? error.message
          : $_('adminReports.errorLoad');
    } finally {
      loading = false;
    }
    await goalsReload;
  }

  async function loadGoalsReport(): Promise<void> {
    if (!goalsReportStale) return;

    const requestId = ++goalsRequestSequence;
    // Cleared immediately, not left showing the previous range's numbers while the
    // new request is in flight — otherwise the template's "do we have data"
    // check would keep matching on stale data instead of falling through to the
    // loading state below.
    goalsReport = null;
    goalsLoading = true;
    goalsError = null;
    try {
      const query = new URLSearchParams({ from: rangeStart, to: rangeEnd });
      const result = await apiGet<GoalsReport>(
        `/api/admin/reports/goals?${query}`,
      );
      if (requestId !== goalsRequestSequence) return; // superseded by a newer request
      goalsReport = result;
      goalsReportStale = false;
    } catch (error) {
      if (requestId !== goalsRequestSequence) return;
      goalsError =
        error instanceof ApiError
          ? error.message
          : $_('adminReports.errorLoad');
    } finally {
      if (requestId === goalsRequestSequence) goalsLoading = false;
    }
  }

  function selectTab(tab: ReportTabKey): void {
    activeReportTab = tab;
    if (tab === 'goals') void loadGoalsReport();
  }

  async function handleGenerate(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    await loadReport();
  }

  const monthLabels = $derived(
    report?.trend.map((month) => formatDisplayMonth(month.month)) ?? [],
  );
  const goalsMonthLabels = $derived(
    goalsReport?.byMonth.map((month) => formatDisplayMonth(month.month)) ?? [],
  );
</script>

{#snippet rightNowCard(
  headingKey: string,
  totalInProgress: number,
  overdueInProgress: number,
)}
  <div class="card goals-right-now">
    <h3>{$_(headingKey)}</h3>
    <p>
      {$_('adminReports.totalInProgressLabel')}:
      <strong>{totalInProgress}</strong>
      &nbsp;·&nbsp;
      {$_('adminReports.overdueInProgressLabel')}:
      <strong
        class="tag {overdueInProgress > 0 ? 'tag-accent' : 'tag-accent-2'}"
        >{overdueInProgress}</strong
      >
    </p>
  </div>
{/snippet}

<main>
  <h1>{$_('adminReports.title')}</h1>

  <AdminGate onReady={loadInitialReport} errorLoadKey="adminReports.errorLoad">
    <AdminTabStrip active="reports" />

    <form class="card filters" onsubmit={handleGenerate}>
      <fieldset>
        <legend>{$_('report.dateRangeLegend')}</legend>
        <button
          type="button"
          class="btn btn-secondary"
          onclick={applyQuarterPreset}>{$_('report.quarterPreset')}</button
        >
        <div class="range-row">
          <div class="field">
            <label for="range-from">{$_('report.fromLabel')}</label>
            <DateInput id="range-from" bind:value={rangeStart} />
          </div>
          <div class="field">
            <label for="range-to">{$_('report.toLabel')}</label>
            <DateInput id="range-to" bind:value={rangeEnd} />
          </div>
        </div>
      </fieldset>

      {#if reportError}
        <p class="banner-error">{reportError}</p>
      {/if}

      <button
        type="submit"
        class="btn btn-primary"
        disabled={loading || goalsLoading || !rangeStart || !rangeEnd}
      >
        {loading ? $_('adminReports.generating') : $_('adminReports.generate')}
      </button>
    </form>

    {#if report}
      <div class="report-tabs" role="tablist">
        {#each REPORT_TABS as tab (tab.key)}
          <button
            type="button"
            role="tab"
            aria-selected={activeReportTab === tab.key}
            class="tab-btn"
            class:tab-btn-active={activeReportTab === tab.key}
            onclick={() => selectTab(tab.key)}
          >
            {$_(tab.labelKey)}
          </button>
        {/each}
      </div>

      {#if activeReportTab === 'overview'}
        <div class="tiles">
          <div class="card tile">
            <span class="tile-label">{$_('adminReports.meetingsLabel')}</span>
            <span class="tile-value">{report.meetings.total}</span>
          </div>
          <div class="card tile">
            <span class="tile-label">{$_('adminReports.completedLabel')}</span>
            <span class="tile-value">{report.meetings.completed}</span>
          </div>
          <div class="card tile">
            <span class="tile-label"
              >{$_('adminReports.responseRateLabel')}</span
            >
            <span class="tile-value"
              >{Math.round(report.meetings.responseRate * 100)}%</span
            >
          </div>
          <div class="card tile">
            <span class="tile-label">{$_('adminReports.overdueLabel')}</span>
            <span
              class="tile-value tag {report.meetings.overdueOpen > 0
                ? 'tag-accent'
                : 'tag-accent-2'}">{report.meetings.overdueOpen}</span
            >
          </div>
          <div class="card tile">
            <span class="tile-label"
              >{$_('adminReports.goalsAchievedLabel')}</span
            >
            <span class="tile-value">{report.goals.achievedInRange}</span>
          </div>
        </div>

        <div class="charts">
          <div class="card">
            <h3>{$_('adminReports.meetingsPerMonthHeading')}</h3>
            <BarChart
              labels={monthLabels}
              values={report.trend.map((month) => month.meetingsCompleted)}
              label={$_('adminReports.meetingsPerMonthHeading')}
            />
          </div>
          <div class="card">
            <h3>{$_('adminReports.goalsAchievedPerMonthHeading')}</h3>
            <BarChart
              labels={monthLabels}
              values={report.trend.map((month) => month.goalsAchieved)}
              label={$_('adminReports.goalsAchievedPerMonthHeading')}
            />
          </div>
        </div>

        {@render rightNowCard(
          'adminReports.goalsRightNowHeading',
          report.goals.totalInProgress,
          report.goals.overdueInProgress,
        )}

        <p class="text-muted users-line">
          {$_('adminReports.usersActiveLabel')}: {report.users.active}
          &nbsp;·&nbsp;
          {$_('adminReports.usersBlockedLabel')}: {report.users.blocked}
          &nbsp;·&nbsp;
          {$_('adminReports.usersAdminsLabel')}: {report.users.admins}
        </p>
      {:else if goalsError}
        <p class="banner-error">{goalsError}</p>
      {:else if goalsReport}
        <div class="card goals-in-range">
          <h3>{$_('adminReports.goalsInRangeHeading')}</h3>
          <p>
            {$_('adminReports.createdLabel')}:
            <strong>{goalsReport.createdInRange}</strong>
            &nbsp;·&nbsp;
            {$_('adminReports.achievedLabel')}:
            <strong>{goalsReport.achievedInRange}</strong>
            &nbsp;·&nbsp;
            {$_('adminReports.cancelledLabel')}:
            <strong>{goalsReport.cancelledInRange}</strong>
          </p>
        </div>

        {@render rightNowCard(
          'adminReports.rightNowHeading',
          goalsReport.totalInProgress,
          goalsReport.overdueInProgress,
        )}

        <div class="card">
          <h3>{$_('adminReports.goalsAchievedPerMonthHeading')}</h3>
          <BarChart
            labels={goalsMonthLabels}
            values={goalsReport.byMonth.map((month) => month.achieved)}
            label={$_('adminReports.goalsAchievedPerMonthHeading')}
          />
        </div>
      {:else if goalsLoading}
        <p class="text-muted">{$_('common.loading')}</p>
      {/if}
    {/if}
  </AdminGate>
</main>

<style>
  main {
    max-width: 56rem;
    margin: 0 auto;
    padding: 32px 24px 60px;
  }

  h1 {
    font-size: 28px;
    margin-bottom: 20px;
  }

  h3 {
    margin: 0 0 12px;
  }

  .filters {
    gap: 16px;
    margin-bottom: 20px;
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

  .report-tabs {
    display: flex;
    gap: 4px;
    margin-bottom: 20px;
    border-bottom: 1px solid var(--color-divider);
  }

  .tab-btn {
    padding: 8px 4px;
    font-size: 14px;
    color: var(--color-text-muted);
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    margin-right: 16px;
    cursor: pointer;
  }

  .tab-btn:hover {
    color: var(--color-accent-ink);
  }

  .tab-btn-active {
    color: var(--color-text);
    border-bottom-color: var(--color-accent);
    font-weight: var(--font-heading-weight);
  }

  .goals-in-range {
    margin-bottom: 20px;
  }

  .goals-in-range p {
    margin: 0;
  }

  .tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
  }

  .tile {
    display: flex;
    flex-direction: column;
    gap: 4px;
    align-items: flex-start;
  }

  .tile-label {
    font-size: 12px;
    color: var(--color-text-muted);
  }

  .tile-value {
    font-size: 24px;
    font-family: var(--font-heading);
    font-weight: var(--font-heading-weight);
  }

  .charts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
  }

  .goals-right-now {
    margin-bottom: 20px;
  }

  .goals-right-now p {
    margin: 0;
  }

  .users-line {
    font-size: 13px;
  }
</style>
