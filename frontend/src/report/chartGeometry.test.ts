import { describe, expect, it } from 'vitest';
import { barChartGeometry } from './chartGeometry';

describe('barChartGeometry', () => {
  it('returns no bars for an empty series', () => {
    expect(barChartGeometry([], 60, 20)).toEqual([]);
  });

  it('scales bar heights proportionally to the series max', () => {
    const bars = barChartGeometry([1, 2, 4], 60, 20);

    expect(bars[0].height).toBeCloseTo(5);
    expect(bars[1].height).toBeCloseTo(10);
    expect(bars[2].height).toBeCloseTo(20);
  });

  it('renders a single-value series sensibly, without NaN from a zero-range scale', () => {
    const bars = barChartGeometry([7], 60, 20);

    expect(bars).toHaveLength(1);
    expect(bars[0].height).toBeCloseTo(20);
    expect(Number.isNaN(bars[0].height)).toBe(false);
  });

  it('renders flat (zero-height) bars for an all-zero series instead of crashing', () => {
    const bars = barChartGeometry([0, 0, 0], 60, 20);

    expect(bars).toHaveLength(3);
    for (const bar of bars) {
      expect(bar.height).toBe(0);
      expect(Number.isNaN(bar.height)).toBe(false);
    }
  });

  it('positions bars left-to-right evenly across the given width', () => {
    const bars = barChartGeometry([1, 1], 60, 20);

    expect(bars[0].x).toBe(0);
    expect(bars[1].x).toBe(30);
  });
});
