import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { unloadWarning } from './unloadWarning';

let listeners: Set<EventListener>;

function warns(): boolean {
  let prevented = false;
  const event = {
    preventDefault: () => {
      prevented = true;
    },
    returnValue: undefined,
  } as unknown as Event;
  for (const listener of listeners) listener(event);
  return prevented;
}

beforeEach(() => {
  listeners = new Set();
  vi.stubGlobal('window', {
    addEventListener: (_type: string, listener: EventListener) =>
      listeners.add(listener),
    removeEventListener: (_type: string, listener: EventListener) =>
      listeners.delete(listener),
  });
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('unloadWarning', () => {
  it('attaches one listener only while needed', () => {
    const setWarning = unloadWarning();
    setWarning(false);
    expect(listeners.size).toBe(0);

    setWarning(true);
    setWarning(true);
    expect(listeners.size).toBe(1);
    expect(warns()).toBe(true);

    setWarning(false);
    expect(listeners.size).toBe(0);
  });

  it('keeps independent warnings apart', () => {
    const first = unloadWarning();
    const second = unloadWarning();
    first(true);
    second(true);
    expect(listeners.size).toBe(2);

    first(false);
    expect(listeners.size).toBe(1);
    expect(warns()).toBe(true);
  });

  it('checks stillNeeded again when the tab closes', () => {
    let needed = true;
    const setWarning = unloadWarning(() => needed);
    setWarning(true);

    needed = false;

    expect(warns()).toBe(false);
  });
});
