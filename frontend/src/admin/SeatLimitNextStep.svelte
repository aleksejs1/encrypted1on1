<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { PATHS } from '../routes';
  import type { SeatLimitStep } from './seatLimit';

  /** What follows a "no seats left" error inside its banner (seatLimit.ts). */
  const { step }: { step: SeatLimitStep } = $props();
</script>

{#if step === 'view-plan'}
  <a href={PATHS.adminPlan}>{$_('inviteForm.viewPlan')}</a>
{:else if step === 'ask-admin'}
  {$_('inviteForm.askAdmin')}
{/if}
