import { afterEach, describe, expect, it, vi } from 'vitest';
import { pause } from './pause';

describe('pause', () => {
  afterEach(() => {
    vi.useRealTimers();
  });

  /** Whether `promise` has settled by now. */
  async function settled(promise: Promise<void>): Promise<boolean> {
    let done = false;
    void promise.then(() => (done = true));
    await Promise.resolve();
    await Promise.resolve();
    return done;
  }

  it('resolves after the time', async () => {
    vi.useFakeTimers();
    const signal = new AbortController().signal;
    const waiting = pause(2_000, signal);
    vi.advanceTimersByTime(1_999);
    expect(await settled(waiting)).toBe(false);
    vi.advanceTimersByTime(1);
    expect(await settled(waiting)).toBe(true);
  });

  it('resolves at once when the signal aborts, and leaves no timer', async () => {
    vi.useFakeTimers();
    const controller = new AbortController();
    const waiting = pause(2_000, controller.signal);
    controller.abort();
    expect(await settled(waiting)).toBe(true);
    expect(vi.getTimerCount()).toBe(0);
  });

  it('resolves at once on a signal that has already aborted', async () => {
    vi.useFakeTimers();
    const controller = new AbortController();
    controller.abort();
    expect(await settled(pause(2_000, controller.signal))).toBe(true);
    expect(vi.getTimerCount()).toBe(0);
  });

  it('stops listening once the time is up', () => {
    vi.useFakeTimers();
    const controller = new AbortController();
    const removed = vi.spyOn(controller.signal, 'removeEventListener');
    void pause(2_000, controller.signal);
    vi.advanceTimersByTime(2_000);
    expect(removed).toHaveBeenCalledWith('abort', expect.any(Function));
  });
});
