import type { FieldOption } from './questions';

/**
 * Maps each row's radio value to its index within the field's own options
 * array — the field's already-defined UI ordering *is* the scale
 * (bad→0, neutral→1, good→2), not an invented "goodness" judgment.
 * Rows with a missing/unrecognized value are skipped, not zero-filled —
 * a gap in the data shouldn't silently read as "bad."
 */
export function extractTrendValues(
  rows: { value: string | undefined }[],
  options: FieldOption[],
): number[] {
  const values: number[] = [];
  for (const row of rows) {
    const index = options.findIndex((option) => option.value === row.value);
    if (index !== -1) values.push(index);
  }
  return values;
}

/**
 * Pure SVG geometry: evenly spaces `values.length` points across `width`,
 * inverts y (a higher option-index — further right/more positive in the
 * field's own options list — should sit visually higher on the chart;
 * SVG y grows downward) scaled by `maxIndex`. Returns the exact string a
 * <polyline points="..."> attribute needs.
 */
export function sparklinePoints(
  values: number[],
  maxIndex: number,
  width: number,
  height: number,
): string {
  if (values.length === 0) return '';
  const stepX = values.length > 1 ? width / (values.length - 1) : 0;

  return values
    .map((value, i) => {
      const x = i * stepX;
      const y =
        maxIndex > 0 ? height - (value / maxIndex) * height : height / 2;
      return `${x},${y}`;
    })
    .join(' ');
}
