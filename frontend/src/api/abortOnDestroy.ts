import { onDestroy } from 'svelte';

/**
 * An `AbortSignal` that aborts when the calling component is destroyed — for
 * cancelling a page's own passive, mount-triggered GET fetches so a rapid
 * navigation away doesn't leave them running for nothing (see GitHub issue
 * #66). Only meant for read-only, component-lifetime-scoped fetches — not
 * for a multi-step write flow a user explicitly started (e.g. AnketaList's
 * reshareAll), which stays uncancelled once begun so a reshare in progress
 * always finishes regardless of navigation.
 */
export function abortOnDestroy(): AbortSignal {
  const controller = new AbortController();
  onDestroy(() => controller.abort());
  return controller.signal;
}

/** Whether a rejection came from an `abortOnDestroy()` signal firing, as opposed to a real request failure. */
export function isAbortError(error: unknown): boolean {
  return error instanceof DOMException && error.name === 'AbortError';
}
