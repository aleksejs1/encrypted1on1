// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
  answersFingerprint,
  isSaveShortcut,
  saveShortcutPlace,
} from './answersEdit';
import type { Answers } from './questions';

function answersChanged(current: Answers, before: Answers): boolean {
  return answersFingerprint(current) !== answersFingerprint(before);
}

describe('answersFingerprint', () => {
  const before = {
    mood: 'good',
    feelings: ['calm', 'focused'],
    achievements: [{ id: 'a1', date: '2026-09-01', text: 'Shipped' }],
  };

  it('is false for the same answers', () => {
    expect(answersChanged(structuredClone(before), before)).toBe(false);
  });

  it('ignores key order', () => {
    const reordered = {
      achievements: before.achievements,
      feelings: before.feelings,
      mood: before.mood,
    };
    expect(answersChanged(reordered, before)).toBe(false);
  });

  it('detects a changed text answer', () => {
    expect(answersChanged({ ...before, mood: 'great' }, before)).toBe(true);
  });

  it('ignores checkbox order: unchecking and re-checking an option appends it', () => {
    expect(
      answersChanged({ ...before, feelings: ['focused', 'calm'] }, before),
    ).toBe(false);
  });

  it('keeps list entry order', () => {
    const entries = [
      { id: 'a1', date: '2026-09-01', text: 'One' },
      { id: 'a2', date: '2026-09-02', text: 'Two' },
    ];
    expect(
      answersChanged(
        { achievements: [...entries].reverse() },
        { achievements: entries },
      ),
    ).toBe(true);
  });

  it('detects a changed checkbox selection', () => {
    expect(answersChanged({ ...before, feelings: ['calm'] }, before)).toBe(
      true,
    );
  });

  it('detects an edited, added or removed list entry', () => {
    const edited = [{ ...before.achievements[0], text: 'Shipped it' }];
    expect(answersChanged({ ...before, achievements: edited }, before)).toBe(
      true,
    );
    const added = [
      ...before.achievements,
      { id: 'a2', date: '2026-09-02', text: 'More' },
    ];
    expect(answersChanged({ ...before, achievements: added }, before)).toBe(
      true,
    );
    expect(answersChanged({ ...before, achievements: [] }, before)).toBe(true);
  });

  it('detects a newly answered field', () => {
    expect(answersChanged({ ...before, extra: 'note' }, before)).toBe(true);
  });

  it('treats a field typed into and cleared again as unchanged', () => {
    expect(
      answersChanged(
        { ...before, extra: '', spaces: '  \n', other: [], gone: undefined },
        before,
      ),
    ).toBe(false);
  });
});

describe('isSaveShortcut', () => {
  function key(init: KeyboardEventInit): KeyboardEvent {
    return {
      key: 's',
      code: '',
      ctrlKey: false,
      metaKey: false,
      altKey: false,
      shiftKey: false,
      ...init,
    } as KeyboardEvent;
  }

  it('matches Ctrl+S and ⌘S, with Caps Lock on too', () => {
    expect(isSaveShortcut(key({ ctrlKey: true }))).toBe(true);
    expect(isSaveShortcut(key({ metaKey: true }))).toBe(true);
    expect(isSaveShortcut(key({ ctrlKey: true, key: 'S' }))).toBe(true);
  });

  it('matches the S key on a non-Latin layout', () => {
    expect(isSaveShortcut(key({ ctrlKey: true, key: 'ы', code: 'KeyS' }))).toBe(
      true,
    );
  });

  it("leaves the physical S key alone on a Latin layout that types another letter there (Dvorak's Ctrl+O)", () => {
    expect(isSaveShortcut(key({ ctrlKey: true, key: 'o', code: 'KeyS' }))).toBe(
      false,
    );
  });

  it('ignores a plain s and other combinations', () => {
    expect(isSaveShortcut(key({}))).toBe(false);
    expect(isSaveShortcut(key({ ctrlKey: true, shiftKey: true }))).toBe(false);
    expect(isSaveShortcut(key({ ctrlKey: true, altKey: true }))).toBe(false);
    expect(isSaveShortcut(key({ ctrlKey: true, key: 'a', code: 'KeyA' }))).toBe(
      false,
    );
  });
});

describe('saveShortcutPlace', () => {
  let mySide: HTMLElement;
  const el = (selector: string) =>
    document.querySelector<HTMLElement>(selector)!;

  beforeEach(() => {
    document.body.innerHTML = `
      <section id="my-side">
        <div data-answers-edit="header"><h2 id="heading">My side</h2></div>
        <div data-answers-edit="bar"><button id="bar-save">Save</button></div>
        <div data-question-block>
          <h4 id="question">Mood</h4>
          <div data-field-id="mood"><textarea id="answer"></textarea></div>
          <div data-field-id="wins">
            <form data-add-entry>
              <input id="add-entry" /><button id="add-button">Add</button>
            </form>
          </div>
          <div data-comment-thread><input id="comment" /></div>
        </div>
        <div data-answers-edit="bottom"><button id="bottom-save">Save</button></div>
      </section>
      <section id="other-side"><textarea id="other"></textarea></section>
    `;
    mySide = el('#my-side');
  });

  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('finds the answer field and its question', () => {
    expect(saveShortcutPlace(el('#answer'), mySide)).toEqual({
      at: 'field',
      block: el('[data-question-block]'),
      fieldId: 'mood',
    });
  });

  it('finds the edit controls at the top and bottom', () => {
    expect(saveShortcutPlace(el('#bar-save'), mySide)).toEqual({ at: 'top' });
    expect(saveShortcutPlace(el('#heading'), mySide)).toEqual({ at: 'top' });
    expect(saveShortcutPlace(el('#bottom-save'), mySide)).toEqual({
      at: 'bottom',
    });
  });

  it("takes anything else in my side, with its question's block", () => {
    expect(saveShortcutPlace(el('#question'), mySide)).toEqual({
      at: 'side',
      block: el('[data-question-block]'),
    });
  });

  it('tells apart an "Add an entry" form holding text not added yet', () => {
    const input = el('#add-entry') as HTMLInputElement;
    expect(saveShortcutPlace(input, mySide)).toMatchObject({
      at: 'field',
      fieldId: 'wins',
    });
    input.value = 'Shipped';
    expect(saveShortcutPlace(input, mySide)).toEqual({ at: 'unadded-entry' });
    expect(saveShortcutPlace(el('#add-button'), mySide)).toEqual({
      at: 'unadded-entry',
    });
  });

  it('leaves comment threads and the rest of the page alone', () => {
    expect(saveShortcutPlace(el('#comment'), mySide)).toBeNull();
    expect(saveShortcutPlace(el('#other'), mySide)).toBeNull();
    expect(saveShortcutPlace(document.body, mySide)).toBeNull();
    expect(saveShortcutPlace(el('#answer'), undefined)).toBeNull();
    expect(saveShortcutPlace(null, mySide)).toBeNull();
  });
});
