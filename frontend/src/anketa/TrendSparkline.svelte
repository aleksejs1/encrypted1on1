<script lang="ts">
  import { sparklinePoints } from './moodWorkloadTrend';

  const {
    values,
    maxIndex,
    label,
  }: {
    values: number[];
    maxIndex: number;
    label: string;
  } = $props();

  const WIDTH = 60;
  const HEIGHT = 20;

  const points = $derived(sparklinePoints(values, maxIndex, WIDTH, HEIGHT));
</script>

{#if values.length >= 2}
  <svg
    width={WIDTH}
    height={HEIGHT}
    viewBox="0 0 {WIDTH} {HEIGHT}"
    role="img"
    aria-label={label}
  >
    <polyline
      {points}
      fill="none"
      stroke="var(--color-accent)"
      stroke-width="2"
      stroke-linecap="round"
      stroke-linejoin="round"
    />
  </svg>
{/if}
