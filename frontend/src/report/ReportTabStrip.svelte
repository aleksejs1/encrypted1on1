<script lang="ts">
  import { _ } from 'svelte-i18n';
  import TabStrip from '../design/TabStrip.svelte';
  import { PATHS } from '../routes';

  const TABS = [
    { key: 'report', href: PATHS.report, labelKey: 'report.reportTab' },
    { key: 'notes', href: PATHS.reportNotes, labelKey: 'privateNotes.heading' },
  ] as const;

  const { active }: { active: (typeof TABS)[number]['key'] } = $props();
</script>

<TabStrip
  tabs={TABS.map((tab) => ({ ...tab, label: $_(tab.labelKey) }))}
  {active}
/>
