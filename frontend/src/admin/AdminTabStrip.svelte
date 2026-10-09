<script lang="ts">
  import { _ } from 'svelte-i18n';
  import TabStrip from '../design/TabStrip.svelte';

  const TABS = [
    { key: 'users', href: '/admin', labelKey: 'admin.usersTab' },
    { key: 'reports', href: '/admin/reports', labelKey: 'admin.reportsTab' },
    { key: 'invites', href: '/admin/invites', labelKey: 'admin.invitesTab' },
    {
      key: 'templates',
      href: '/admin/templates',
      labelKey: 'admin.templatesTab',
    },
  ] as const;

  const { active }: { active: (typeof TABS)[number]['key'] } = $props();
</script>

<TabStrip
  tabs={TABS.map((tab) => ({ ...tab, label: $_(tab.labelKey) }))}
  {active}
/>
