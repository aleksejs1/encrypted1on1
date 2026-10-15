import { requestTimeout } from './requestTimeout';

/** One read of a series `latestReads()` started. */
export interface LatestRead {
  /** For the request: aborted by a newer read, by `until`, by the timeout, and by `end()`. */
  signal: AbortSignal;
  /** No newer read has started since: this one's answer, or failure, is the page's. */
  current: () => boolean;
  /** The read is over: drops its listeners on `until` and the timeout. */
  end: () => void;
}

/**
 * Reads of one thing, of which only the last one started counts: starting
 * one aborts the one before. Each gives up after requestTimeout()'s time,
 * as `apiGet()` itself never does, or once `until` aborts (abortOnDestroy()).
 * Used by the Plan page and the Invites tab's seat line, which both read
 * GET /api/admin/billing.
 */
export function latestReads(until: AbortSignal): () => LatestRead {
  let started = 0;
  let running: AbortController | null = null;
  return () => {
    const mine = ++started;
    running?.abort();
    const controller = new AbortController();
    running = controller;
    // By hand, not AbortSignal.any(), which Safari has only since 17.4. The
    // listeners go with the controller, aborted once the read is over.
    for (const signal of [until, requestTimeout(0)]) {
      if (signal.aborted) controller.abort(signal.reason);
      signal.addEventListener('abort', () => controller.abort(signal.reason), {
        once: true,
        signal: controller.signal,
      });
    }
    return {
      signal: controller.signal,
      current: () => mine === started,
      end: () => controller.abort(),
    };
  };
}
