import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  defaultRole,
  clearJustCreated,
  isJustCreated,
  pairRole,
  readLastRole,
  rememberLastRole,
  setJustCreated,
  startCreateAnother,
  startCreateWith,
  takeCreateAnother,
  takeCreateWith,
  type CreateSettings,
} from './createDefaults';
import { invalidateIdentity } from '../crypto/identity.svelte';
import type { Side } from './questions';

function anketa(
  id: string,
  myRole: Side,
  overrides: Partial<{ counterpartId: string; meetingDate: string }> = {},
) {
  return {
    id,
    myRole,
    counterpartId: 'bob',
    meetingDate: '2026-01-01T00:00:00Z',
    ...overrides,
  };
}

describe('pairRole', () => {
  it('is null for a pair with no meetings', () => {
    expect(pairRole([], 'bob')).toBeNull();
    expect(
      pairRole([anketa('a', 'manager', { counterpartId: 'carol' })], 'bob'),
    ).toBeNull();
    expect(pairRole([anketa('a', 'manager')], '')).toBeNull();
  });

  it("is the role from the pair's most recent meeting, whatever the list order", () => {
    const older = anketa('a', 'employee');
    const newer = anketa('b', 'manager', {
      meetingDate: '2026-02-01T00:00:00Z',
    });
    expect(pairRole([older, newer], 'bob')).toBe('manager');
    expect(pairRole([newer, older], 'bob')).toBe('manager');
  });

  it('breaks a meeting-date tie by the later id', () => {
    const first = anketa('a', 'employee');
    const second = anketa('b', 'manager');
    expect(pairRole([first, second], 'bob')).toBe('manager');
    expect(pairRole([second, first], 'bob')).toBe('manager');
  });

  it("ignores other pairs' meetings", () => {
    expect(
      pairRole(
        [
          anketa('a', 'employee'),
          anketa('b', 'manager', {
            counterpartId: 'carol',
            meetingDate: '2026-03-01T00:00:00Z',
          }),
        ],
        'bob',
      ),
    ).toBe('employee');
  });
});

describe('defaultRole', () => {
  it("prefers the pair's history over the last choice", () => {
    expect(defaultRole('manager', 'employee')).toBe('manager');
    expect(defaultRole('employee', 'manager')).toBe('employee');
  });

  it('falls back to the last choice for a new pair', () => {
    expect(defaultRole(null, 'manager')).toBe('manager');
  });

  it('preselects nothing with neither', () => {
    expect(defaultRole(null, null)).toBeNull();
  });
});

describe('last role', () => {
  // The node test environment has no localStorage; only getItem/setItem are used.
  beforeEach(() => {
    const store = new Map<string, string>();
    vi.stubGlobal('localStorage', {
      getItem: (key: string) => store.get(key) ?? null,
      setItem: (key: string, value: string) => store.set(key, value),
    });
  });
  afterEach(() => vi.unstubAllGlobals());

  it('is null until one is remembered', () => {
    expect(readLastRole()).toBeNull();
    rememberLastRole('manager');
    expect(readLastRole()).toBe('manager');
    rememberLastRole('employee');
    expect(readLastRole()).toBe('employee');
  });

  it('treats storage that throws as nothing stored', () => {
    vi.stubGlobal('localStorage', {
      getItem: () => {
        throw new Error('blocked');
      },
      setItem: () => {
        throw new Error('full');
      },
    });
    expect(readLastRole()).toBeNull();
    expect(() => rememberLastRole('manager')).not.toThrow();
  });

  it('ignores a stored value that is not a role', () => {
    localStorage.setItem('e1o1:lastRole', 'admin');
    expect(readLastRole()).toBeNull();
  });
});

describe('create another', () => {
  const settings: CreateSettings = {
    role: 'manager',
    templateChoice: 'onboarding',
    periodicityDays: 14,
  };

  it('hands the just-created meeting’s settings to the form once', () => {
    setJustCreated('a1', settings);
    expect(isJustCreated('a1')).toBe(true);
    expect(takeCreateAnother()).toBeNull();

    startCreateAnother('a1');
    expect(takeCreateAnother()).toEqual(settings);
    expect(takeCreateAnother()).toBeNull();
  });

  it('is offered only on the meeting that was just created', () => {
    setJustCreated('a1', settings);
    expect(isJustCreated('a2')).toBe(false);
    startCreateAnother('a2');
    expect(takeCreateAnother()).toBeNull();
  });

  it('is no longer offered once the meeting’s page was left', () => {
    setJustCreated('a1', settings);
    clearJustCreated();
    expect(isJustCreated('a1')).toBe(false);
    startCreateAnother('a1');
    expect(takeCreateAnother()).toBeNull();
  });

  it('still hands over settings taken before the page was left', () => {
    setJustCreated('a1', settings);
    startCreateAnother('a1');
    clearJustCreated();
    expect(takeCreateAnother()).toEqual(settings);
  });

  it('is dropped once the tab has logged out since', () => {
    setJustCreated('a1', settings);
    invalidateIdentity();
    expect(isJustCreated('a1')).toBe(false);
    startCreateAnother('a1');
    expect(takeCreateAnother()).toBeNull();

    setJustCreated('a1', settings);
    startCreateAnother('a1');
    invalidateIdentity();
    expect(takeCreateAnother()).toBeNull();
  });
});

describe('create with a colleague', () => {
  it('hands the colleague to the form once', () => {
    expect(takeCreateWith()).toBeNull();
    startCreateWith('bob');
    expect(takeCreateWith()).toBe('bob');
    expect(takeCreateWith()).toBeNull();
  });

  it('is dropped once the tab has logged out since', () => {
    startCreateWith('bob');
    invalidateIdentity();
    expect(takeCreateWith()).toBeNull();
  });
});
