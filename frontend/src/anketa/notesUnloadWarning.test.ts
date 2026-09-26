import { beforeEach, describe, expect, it, vi } from 'vitest';

const has = vi.hoisted(() => ({ users: new Set<string>() }));
vi.mock('./notesBackup', () => ({
  hasNotesBackups: (userId: string | null) =>
    userId === null ? has.users.size > 0 : has.users.has(userId),
}));

const { refreshNotesUnloadWarning } = await import('./notesUnloadWarning');

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
  has.users.clear();
  listeners = new Set();
  globalThis.window = {
    addEventListener: (_type: string, listener: EventListener) =>
      listeners.add(listener),
    removeEventListener: (_type: string, listener: EventListener) =>
      listeners.delete(listener),
  } as unknown as Window & typeof globalThis;
});

describe('refreshNotesUnloadWarning', () => {
  it('attaches only while the logged-in user has a notes backup', () => {
    refreshNotesUnloadWarning('user-1');
    expect(listeners.size).toBe(0);

    has.users.add('user-1');
    refreshNotesUnloadWarning('user-1');
    expect(listeners.size).toBe(1);
    expect(warns()).toBe(true);

    has.users.clear();
    refreshNotesUnloadWarning('user-1');
    expect(listeners.size).toBe(0);
  });

  it("never warns another logged-in user about a user's backup", () => {
    has.users.add('user-1');

    refreshNotesUnloadWarning('user-2');

    expect(listeners.size).toBe(0);
  });

  it('warns about any backup once logged out, as after logging out with unsaved notes', () => {
    has.users.add('user-1');

    refreshNotesUnloadWarning(null);

    expect(warns()).toBe(true);
  });

  it('checks the backups again when the tab closes', () => {
    has.users.add('user-1');
    refreshNotesUnloadWarning('user-1');

    has.users.clear();

    expect(warns()).toBe(false);
  });
});
