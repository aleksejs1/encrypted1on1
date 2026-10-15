<script lang="ts">
  import { _ } from 'svelte-i18n';
  import type { SeatLine } from './planPage';
  import type { SeatLimitStep } from './seatLimit';
  import SeatLimitNextStep from './SeatLimitNextStep.svelte';

  /**
   * The seat line of the Plan page, and of the Invites tab above its form
   * (GitHub issue #310), where a full company also gets the next step of
   * the seat-limit error (`seatLimitStep()`; none on the Plan page itself).
   */
  const { seats, step = 'none' }: { seats: SeatLine; step?: SeatLimitStep } =
    $props();
</script>

<p>
  {$_('adminPlan.seats', {
    values: {
      used: seats.used,
      limit: seats.limit,
      people: seats.people,
      pending: seats.pendingInvites,
    },
  })}
</p>
{#if seats.full}
  <p>
    {$_('adminPlan.seatsFull')}
    <SeatLimitNextStep {step} />
  </p>
{/if}

<style>
  /* Spaced by whatever holds them: the Plan page's card, the Invites tab's
     line. */
  p {
    margin: 0;
  }
</style>
