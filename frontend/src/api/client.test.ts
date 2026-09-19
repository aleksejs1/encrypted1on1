import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  apiDelete,
  apiGet,
  apiGetAllPages,
  apiPost,
  apiPut,
  resetCsrfToken,
} from './client';

function jsonResponse(body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });
}

/**
 * A `fetch` stub that actually behaves like the real thing with respect to
 * `init.signal`: if the caller passed an already-aborted signal, it rejects
 * with that signal's abort reason instead of resolving — the same thing a
 * real browser `fetch()` does. Queued responses are only consumed on a call
 * that isn't pre-aborted, so a test asserting "no network call happens once
 * aborted" fails loudly (instead of silently passing) if a future change
 * stops actually forwarding `signal` into the real `fetch()` call.
 */
function makeFetchMock(responses: Response[]): ReturnType<typeof vi.fn> {
  let next = 0;
  return vi.fn((_input: RequestInfo | URL, init?: RequestInit) => {
    if (init?.signal?.aborted) {
      return Promise.reject(init.signal.reason as Error);
    }
    const response = responses[next];
    next += 1;
    return Promise.resolve(response);
  });
}

describe('api/client signal forwarding', () => {
  let fetchMock: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    resetCsrfToken();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  function stub(responses: Response[]): void {
    fetchMock = makeFetchMock(responses);
    vi.stubGlobal('fetch', fetchMock);
  }

  it('forwards the signal to fetch on apiGet', async () => {
    stub([jsonResponse({ ok: true })]);
    const controller = new AbortController();

    await apiGet('/api/anketas', { signal: controller.signal });

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/anketas',
      expect.objectContaining({ signal: controller.signal }),
    );
  });

  it('omits the signal on apiGet when no options are passed', async () => {
    stub([jsonResponse({ ok: true })]);

    await apiGet('/api/anketas');

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/anketas',
      expect.objectContaining({ signal: undefined }),
    );
  });

  it("rejects apiGet without ever reaching fetch's network path when the signal is already aborted", async () => {
    stub([jsonResponse({ ok: true })]);
    const controller = new AbortController();
    controller.abort();

    await expect(
      apiGet('/api/anketas', { signal: controller.signal }),
    ).rejects.toBe(controller.signal.reason);
    // The queued response was never consumed — proof the abort short-circuited
    // before the "network" response could be returned, not that apiGet just
    // happened to reject for some unrelated reason.
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('forwards the signal to both the CSRF-token fetch and the mutating fetch on apiPost', async () => {
    stub([jsonResponse({ token: 'csrf-token' }), jsonResponse({ ok: true })]);
    const controller = new AbortController();

    await apiPost(
      '/api/anketas',
      { title: 'x' },
      { signal: controller.signal },
    );

    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      '/api/csrf-token',
      expect.objectContaining({ signal: controller.signal }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      '/api/anketas',
      expect.objectContaining({ signal: controller.signal }),
    );
  });

  it('never issues the mutating apiPost fetch when the signal is already aborted', async () => {
    stub([jsonResponse({ token: 'csrf-token' }), jsonResponse({ ok: true })]);
    const controller = new AbortController();
    controller.abort();

    await expect(
      apiPost('/api/anketas', { title: 'x' }, { signal: controller.signal }),
    ).rejects.toBe(controller.signal.reason);
    // Only the CSRF-token fetch was attempted (and short-circuited by the
    // abort) — the mutating fetch for the actual POST never ran.
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("respects an already-aborted signal on getCsrfToken's warm-cache path", async () => {
    stub([jsonResponse({ token: 'csrf-token' }), jsonResponse({ ok: true })]);
    // Warm the cache with an unaborted request first.
    await apiPost('/api/anketas', { title: 'x' }, {});
    expect(fetchMock).toHaveBeenCalledTimes(2);

    const controller = new AbortController();
    controller.abort();
    await expect(
      apiPost('/api/anketas', { title: 'y' }, { signal: controller.signal }),
    ).rejects.toBe(controller.signal.reason);
    // Still 2: the cached-token fast path never even reached the mutating fetch.
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('forwards the signal on apiPut', async () => {
    stub([jsonResponse({ token: 'csrf-token' }), jsonResponse({ ok: true })]);
    const controller = new AbortController();

    await apiPut(
      '/api/anketas/1',
      { title: 'x' },
      { signal: controller.signal },
    );

    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      '/api/anketas/1',
      expect.objectContaining({ signal: controller.signal }),
    );
  });

  it('forwards the signal on apiDelete', async () => {
    stub([jsonResponse({ token: 'csrf-token' }), jsonResponse({ ok: true })]);
    const controller = new AbortController();

    await apiDelete('/api/anketas/1', {}, { signal: controller.signal });

    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      '/api/anketas/1',
      expect.objectContaining({ signal: controller.signal }),
    );
  });

  it('forwards the signal to every page fetched by apiGetAllPages', async () => {
    stub([jsonResponse([{ id: 1 }]), jsonResponse([])]);
    const controller = new AbortController();

    await apiGetAllPages('/api/anketas', { signal: controller.signal });

    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      '/api/anketas?page=1',
      expect.objectContaining({ signal: controller.signal }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      '/api/anketas?page=2',
      expect.objectContaining({ signal: controller.signal }),
    );
  });
});
