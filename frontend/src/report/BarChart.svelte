<script lang="ts">
  import { barChartGeometry } from './chartGeometry';

  const {
    labels,
    values,
    label,
  }: {
    /** One per value, e.g. formatted months — used for the per-bar tooltip, not drawn as axis text (see the reporting proposal's §8.1: a native <title> is a free, accessible tooltip). */
    labels: string[];
    values: number[];
    /** This chart's own accessible name (svg[role=img][aria-label]). */
    label: string;
  } = $props();

  const WIDTH = 240;
  const HEIGHT = 60;

  const bars = $derived(barChartGeometry(values, WIDTH, HEIGHT));
</script>

<svg
  width={WIDTH}
  height={HEIGHT}
  viewBox="0 0 {WIDTH} {HEIGHT}"
  role="img"
  aria-label={label}
>
  {#each bars as bar, i (i)}
    <rect
      x={bar.x}
      y={bar.y}
      width={bar.width}
      height={bar.height}
      fill="var(--color-accent)"
    >
      <title>{labels[i]}: {values[i]}</title>
    </rect>
  {/each}
</svg>

<style>
  svg {
    width: 100%;
    height: auto;
    max-width: 100%;
  }
</style>
