/**
 * Pure SVG geometry for a simple month-over-month bar chart — same
 * "given these numbers and a target size, return plain geometry" shape as
 * anketa/moodWorkloadTrend.ts's own sparklinePoints(), reused here instead
 * of a charting library (see the reporting proposal's §9: this app has
 * exactly two dependencies today, and a handful of `<rect>`s doesn't need a
 * third).
 */
export interface BarRect {
  x: number;
  y: number;
  width: number;
  height: number;
}

/**
 * One bar per value, evenly spaced across `width`, height proportional to
 * that value's share of the series' own max — not a fixed 0..100 scale, so
 * the tallest bar always reaches the top regardless of the series' actual
 * magnitude. An all-zero (or empty) series scales every bar to height 0
 * rather than dividing by zero — a flat baseline, not a crash.
 */
export function barChartGeometry(
  values: number[],
  width: number,
  height: number,
): BarRect[] {
  if (values.length === 0) return [];

  const max = Math.max(...values, 0);
  const barWidth = width / values.length;
  const gap = barWidth * 0.2;

  return values.map((value, i) => {
    const barHeight = max > 0 ? (value / max) * height : 0;
    return {
      x: i * barWidth,
      y: height - barHeight,
      width: Math.max(barWidth - gap, 0),
      height: barHeight,
    };
  });
}
