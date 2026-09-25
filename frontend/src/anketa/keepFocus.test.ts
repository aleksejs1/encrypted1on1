// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  beginAction,
  fallbackFocusOptions,
  findRow,
  ignoreHeldEnter,
  refocus,
} from './keepFocus';

let root: HTMLDivElement;
let outside: HTMLButtonElement;

beforeEach(() => {
  document.body.innerHTML = `
    <div id="root"><button class="a">A</button><button class="b">B</button></div>
    <button id="outside">Outside</button>
  `;
  root = document.querySelector<HTMLDivElement>('#root')!;
  outside = document.querySelector<HTMLButtonElement>('#outside')!;
});

afterEach(() => {
  document.body.innerHTML = '';
});

describe('refocus, right after the press', () => {
  it('focuses the selector inside the root', async () => {
    await refocus(root, '.b');
    expect(document.activeElement).toBe(root.querySelector('.b'));
  });

  it('moves focus even from outside the root', async () => {
    // A Safari mouse click doesn't focus the button it clicks.
    outside.focus();
    await refocus(root, '.b');
    expect(document.activeElement).toBe(root.querySelector('.b'));
  });

  it('calls onRootGone instead once the root is unmounted', async () => {
    root.remove();
    const onRootGone = vi.fn();
    await refocus(root, '.b', { onRootGone });
    expect(onRootGone).toHaveBeenCalledOnce();
  });

  it('calls onRootGone when there is no root at all', async () => {
    const onRootGone = vi.fn();
    await refocus(undefined, '.b', { onRootGone });
    expect(onRootGone).toHaveBeenCalledOnce();
  });

  it('does nothing when the selector matches nothing', async () => {
    await refocus(root, '.missing');
    expect(document.activeElement).toBe(document.body);
  });
});

describe('refocus, after a request', () => {
  it('focuses the selector when focus was dropped to <body>', async () => {
    const startedOn = beginAction();
    expect(document.activeElement).toBe(document.body);
    await refocus(root, '.b', { startedOn });
    expect(document.activeElement).toBe(root.querySelector('.b'));
  });

  it('focuses the selector when focus is still inside the root', async () => {
    const startedOn = beginAction();
    root.querySelector<HTMLElement>('.a')!.focus();
    await refocus(root, '.b', { startedOn });
    expect(document.activeElement).toBe(root.querySelector('.b'));
  });

  it('leaves focus alone once it was moved elsewhere', async () => {
    const startedOn = beginAction();
    outside.focus();
    const onRootGone = vi.fn();
    await refocus(root, '.b', { onRootGone, startedOn });
    expect(document.activeElement).toBe(outside);
    root.remove();
    await refocus(root, '.b', { onRootGone, startedOn });
    expect(onRootGone).not.toHaveBeenCalled();
  });

  it('calls onRootGone once the root is unmounted', async () => {
    const startedOn = beginAction();
    root.remove();
    const onRootGone = vi.fn();
    await refocus(root, '.b', { onRootGone, startedOn });
    expect(onRootGone).toHaveBeenCalledOnce();
  });

  it.each(['pointerdown', 'keydown', 'wheel', 'touchmove'])(
    'does nothing once the user has interacted since (%s)',
    async (type) => {
      const startedOn = beginAction();
      outside.dispatchEvent(new Event(type, { bubbles: true }));
      const onRootGone = vi.fn();
      await refocus(root, '.b', { onRootGone, startedOn });
      expect(document.activeElement).toBe(document.body);
      root.remove();
      await refocus(root, '.b', { onRootGone, startedOn });
      expect(onRootGone).not.toHaveBeenCalled();
    },
  );

  it("doesn't count a press on a disabled control, like a double-click's second", async () => {
    const startedOn = beginAction();
    outside.disabled = true;
    outside.dispatchEvent(new Event('pointerdown', { bubbles: true }));
    await refocus(root, '.b', { startedOn });
    expect(document.activeElement).toBe(root.querySelector('.b'));
  });

  it('still counts a scroll that starts over a disabled control', async () => {
    const startedOn = beginAction();
    outside.disabled = true;
    outside.dispatchEvent(new Event('touchmove', { bubbles: true }));
    await refocus(root, '.b', { startedOn });
    expect(document.activeElement).toBe(document.body);
  });

  it("doesn't count a modifier key on its own", async () => {
    const startedOn = beginAction();
    for (const key of ['Shift', 'Control', 'Alt', 'Meta']) {
      outside.dispatchEvent(
        new KeyboardEvent('keydown', { key, bubbles: true }),
      );
    }
    await refocus(root, '.b', { startedOn });
    expect(document.activeElement).toBe(root.querySelector('.b'));
  });

  it("doesn't count a held key's auto-repeat as an interaction", async () => {
    const startedOn = beginAction();
    outside.dispatchEvent(
      new KeyboardEvent('keydown', {
        key: 'Enter',
        repeat: true,
        bubbles: true,
      }),
    );
    await refocus(root, '.b', { startedOn });
    expect(document.activeElement).toBe(root.querySelector('.b'));
  });

  it('does nothing once the page has moved on since the action started', async () => {
    const startedOn = beginAction();
    const original = location.pathname;
    history.pushState(null, '', '/anketas/other');
    try {
      await refocus(root, '.b', { startedOn });
      expect(document.activeElement).toBe(document.body);
    } finally {
      history.replaceState(null, '', original);
    }
  });
});

describe('findRow', () => {
  it('finds the row by attribute, escaping the id', () => {
    const id = 'a"b]c';
    root.innerHTML = `<div data-row-id="other"></div><div data-row-id="${id.replace('"', '&quot;')}"></div>`;
    expect(findRow(root, 'data-row-id', id)).toBe(root.lastElementChild);
  });

  it('is undefined when the row or the container is missing', () => {
    expect(findRow(root, 'data-row-id', 'missing')).toBeUndefined();
    expect(findRow(undefined, 'data-row-id', 'x')).toBeUndefined();
  });
});

describe('fallbackFocusOptions', () => {
  it('scrolls for a keyboard click (detail 0)', () => {
    expect(
      fallbackFocusOptions(new MouseEvent('click', { detail: 0 })),
    ).toEqual({ preventScroll: false });
  });

  it('does not scroll for a mouse or touch click', () => {
    expect(
      fallbackFocusOptions(new MouseEvent('click', { detail: 1 })),
    ).toEqual({ preventScroll: true });
  });
});

describe('ignoreHeldEnter', () => {
  const keydown = (key: string, repeat: boolean) =>
    new KeyboardEvent('keydown', { key, repeat, cancelable: true });

  it('drops an auto-repeated Enter', () => {
    const event = keydown('Enter', true);
    ignoreHeldEnter(event);
    expect(event.defaultPrevented).toBe(true);
  });

  it('keeps a first Enter press', () => {
    const event = keydown('Enter', false);
    ignoreHeldEnter(event);
    expect(event.defaultPrevented).toBe(false);
  });

  it('keeps other held keys', () => {
    const event = keydown('a', true);
    ignoreHeldEnter(event);
    expect(event.defaultPrevented).toBe(false);
  });
});
