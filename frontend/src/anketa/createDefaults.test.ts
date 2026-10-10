import { describe, expect, it } from 'vitest';
import {
  clearJustCreated,
  isJustCreated,
  roleStandsFor,
  setJustCreated,
  startCreateAnother,
  startCreateWith,
  takeCreateAnother,
  takeCreateWith,
  type CreateSettings,
} from './createDefaults';
import { invalidateIdentity } from '../crypto/identity.svelte';

describe('roleStandsFor', () => {
  it('keeps a role chosen before any colleague', () => {
    expect(roleStandsFor('', 'bob')).toBe(true);
  });

  it('keeps a role when the same colleague is chosen again', () => {
    expect(roleStandsFor('bob', 'bob')).toBe(true);
  });

  it('drops a role chosen with another colleague selected', () => {
    expect(roleStandsFor('bob', 'carol')).toBe(false);
  });
});

describe('create another', () => {
  const settings: CreateSettings = {
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
