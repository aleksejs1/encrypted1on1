import { describe, expect, it, vi } from 'vitest';
import { latestReads } from './latestRead';

// AbortSignal.timeout() runs on the platform's clock, not vitest's: each
// read's timeout is a controller here, fired by the test.
const timeouts = vi.hoisted(() => [] as AbortController[]);
vi.mock('./requestTimeout', () => ({
  requestTimeout: (): AbortSignal => {
    const controller = new AbortController();
    timeouts.push(controller);
    return controller.signal;
  },
}));

describe('latestReads', () => {
  it('keeps a read current until a newer one starts, and aborts it then', () => {
    const start = latestReads(new AbortController().signal);
    const first = start();
    expect(first.current()).toBe(true);
    expect(first.signal.aborted).toBe(false);

    const second = start();
    expect(first.current()).toBe(false);
    expect(first.signal.aborted).toBe(true);
    expect(second.current()).toBe(true);
    expect(second.signal.aborted).toBe(false);
  });

  it('leaves the newest read current when it ends', () => {
    const start = latestReads(new AbortController().signal);
    start();
    const second = start();
    second.end();
    expect(second.signal.aborted).toBe(true);
    expect(second.current()).toBe(true);
  });

  it('aborts the running read once `until` aborts, and a read started after it at once', () => {
    const until = new AbortController();
    const start = latestReads(until.signal);
    const running = start();
    until.abort();
    expect(running.signal.aborted).toBe(true);
    expect(start().signal.aborted).toBe(true);
  });

  it('gives a read up after its own request timeout, not another read', () => {
    const start = latestReads(new AbortController().signal);
    const first = start();
    const firstTimeout = timeouts.at(-1);
    const second = start();
    const secondTimeout = timeouts.at(-1);
    firstTimeout?.abort(new DOMException('', 'TimeoutError'));
    expect(second.signal.aborted).toBe(false);
    secondTimeout?.abort(new DOMException('', 'TimeoutError'));
    expect(second.signal.aborted).toBe(true);
    expect((second.signal.reason as DOMException).name).toBe('TimeoutError');
    expect(second.current()).toBe(true);
    expect(first.current()).toBe(false);
  });

  it('drops its listeners once the read ends', () => {
    const until = new AbortController();
    const add = vi.spyOn(until.signal, 'addEventListener');
    const read = latestReads(until.signal)();
    read.end();
    const options = add.mock.calls[0]?.[2] as AddEventListenerOptions;
    expect(options.signal?.aborted).toBe(true);
  });
});
