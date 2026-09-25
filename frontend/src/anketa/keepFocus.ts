import { tick } from 'svelte';

/**
 * Whether keyboard focus may be moved: it's still inside `root`, or it was
 * dropped to <body>. The button that had it is often gone by then: an
 * Edit/Delete/Save/Cancel/Confirm swaps its own buttons out, a deleted row
 * takes its buttons with it, and Chromium blurs a focused button as soon as
 * it's disabled for an in-flight request. Never true when the user has moved
 * focus elsewhere meanwhile, so a slow request doesn't pull it back.
 */
function focusIsFree(root: HTMLElement | undefined): boolean {
  const active = document.activeElement;
  return (
    active === null ||
    active === document.body ||
    (root?.contains(active) ?? false)
  );
}

/** Where and when an action that awaits a request started; see `beginAction()`. */
export interface ActionStart {
  page: string;
  interactions: number;
}

/**
 * Counts the user's own interactions with the page: a pointer press, a key
 * press or a scroll. Moving focus, scrolling, or starting any other action
 * anywhere on the page goes through one of these. Not counted: a held key's
 * auto-repeat, a modifier key on its own (a screen-reader user presses Ctrl
 * to silence speech), and a pointer press on a disabled control, which is
 * where the second click of a double-click on a busy button lands.
 */
let interactions = 0;
let counting = false;

const MODIFIER_KEYS = new Set(['Shift', 'Control', 'Alt', 'Meta']);

function countInteraction(event: Event): void {
  if (
    event instanceof KeyboardEvent &&
    (event.repeat || MODIFIER_KEYS.has(event.key))
  ) {
    return;
  }
  if (
    event.type === 'pointerdown' &&
    event.target instanceof Element &&
    event.target.closest(':disabled')
  ) {
    return;
  }
  interactions++;
}

/** Installed on first use, so importing this module needs no DOM. */
function startCounting(): void {
  if (counting) return;
  counting = true;
  for (const type of ['pointerdown', 'keydown', 'wheel', 'touchmove']) {
    document.addEventListener(type, countInteraction, {
      capture: true,
      passive: true,
    });
  }
}

/**
 * Call at the start of an action that awaits a request (after the press
 * that started it), and pass the result to `refocus()` as `startedOn`. Its
 * refocus is then dropped if the user has done anything on the page since:
 * pressed another button anywhere, scrolled away, or typed elsewhere. Also
 * dropped if the page has moved on, since the anketa page is reused when
 * navigating to another anketa and a late refocus could land on the new
 * one's page.
 */
export function beginAction(): ActionStart {
  startCounting();
  return { page: location.pathname, interactions };
}

function isCurrent(start: ActionStart): boolean {
  return (
    start.page === location.pathname && start.interactions === interactions
  );
}

export interface RefocusOptions {
  /**
   * Called instead once `root` is gone, so the parent can focus something
   * that still exists. Only a delete passes one: for any other action a
   * vanished row isn't the user's doing, so focus is left alone.
   */
  onRootGone?: () => void;
  /**
   * From `beginAction()`, for an action that awaited a request. Without it
   * the action is taken as the user's own press, applied immediately, and
   * focus always moves.
   */
  startedOn?: ActionStart;
}

/**
 * After the DOM catches up with a state change, moves focus to `selector`
 * inside `root` (GitHub issues #149, #151), or calls `onRootGone` if `root`
 * is no longer mounted.
 *
 * After a request (`startedOn`), nothing happens if the action is stale (see
 * `beginAction()`) or focus isn't free. For a row action, pass the row from
 * `findRow()` as `root`, not the whole list, so focus moved anywhere else
 * meanwhile, the same list's add input included, isn't pulled back. Every
 * other control in the row is disabled or swapped out while its request
 * runs, so it can't hold focus in the meantime. A deleted row is detached,
 * which is what sends focus to `onRootGone`.
 */
export async function refocus(
  root: HTMLElement | undefined,
  selector: string,
  { onRootGone, startedOn }: RefocusOptions = {},
): Promise<void> {
  await tick();
  if (startedOn && !(isCurrent(startedOn) && focusIsFree(root))) return;
  if (!root?.isConnected) {
    onRootGone?.();
    return;
  }
  root.querySelector<HTMLElement>(selector)?.focus();
}

/**
 * Focus options for a fallback target (a heading or toggle) after `click`
 * removed the row that had focus. The target may be far from where the user
 * was working. A keyboard user needs it scrolled into view to see where focus
 * went, but a mouse or touch user shouldn't have the page jump. A button
 * activated by Enter or Space fires `click` with `detail === 0`.
 */
export function fallbackFocusOptions(click: MouseEvent): FocusOptions {
  return { preventScroll: click.detail !== 0 };
}

/**
 * Capture-phase keydown handler for a region whose actions move focus (a
 * comment thread, the outcomes card, a list's entries): drops an
 * auto-repeated Enter. Each action lands focus on the next control, so an
 * Enter held a moment too long would otherwise carry over. It would submit an
 * edit just opened, flip Delete/Cancel back and forth, or retry a failed add
 * or delete on every repeat. Not for a region holding a textarea, where a
 * held Enter legitimately adds lines.
 */
export function ignoreHeldEnter(event: KeyboardEvent): void {
  if (event.key === 'Enter' && event.repeat) event.preventDefault();
}

/**
 * The row inside `container` whose `attribute` is `id`, e.g.
 * `data-outcome-id`. Look it up before an action changes anything: a deleted
 * row is gone afterwards, which is how `refocus()` knows to call `onRootGone`.
 */
export function findRow(
  container: HTMLElement | undefined,
  attribute: string,
  id: string,
): HTMLElement | undefined {
  return (
    container?.querySelector<HTMLElement>(
      `[${attribute}="${CSS.escape(id)}"]`,
    ) ?? undefined
  );
}
