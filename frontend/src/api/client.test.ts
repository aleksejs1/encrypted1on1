import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  apiDelete,
  apiGet,
  apiGetAllPages,
  apiPost,
  apiPut,
  resetCsrfToken,
  warmCsrfToken,
} from './client';
import {
  connectionEpoch,
  connectionState,
  recordOffline,
  recordOnline,
} from '../connectivity/connectionState.svelte';

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

describe('api/client keepalive and CSRF warm-up', () => {
  beforeEach(() => {
    resetCsrfToken();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('forwards keepalive on apiPut', async () => {
    const fetchMock = makeFetchMock([
      jsonResponse({ token: 'csrf-token' }),
      jsonResponse({ ok: true }),
    ]);
    vi.stubGlobal('fetch', fetchMock);

    await apiPut('/api/anketas/1/private-notes', {}, { keepalive: true });

    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      '/api/anketas/1/private-notes',
      expect.objectContaining({ keepalive: true }),
    );
  });

  it('caches the token up front, so a later PUT makes only its own request', async () => {
    const fetchMock = makeFetchMock([
      jsonResponse({ token: 'csrf-token' }),
      jsonResponse({ ok: true }),
    ]);
    vi.stubGlobal('fetch', fetchMock);

    await warmCsrfToken();
    await apiPut('/api/anketas/1/private-notes', {});

    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      '/api/csrf-token',
      expect.anything(),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      '/api/anketas/1/private-notes',
      expect.objectContaining({
        headers: expect.objectContaining({ 'X-CSRF-Token': 'csrf-token' }),
      }),
    );
  });

  it('never caches a token fetched across a reset (a logout)', async () => {
    let releaseToken: (response: Response) => void = () => {};
    const fetchMock = vi.fn((input: RequestInfo | URL) =>
      String(input) === '/api/csrf-token' && fetchMock.mock.calls.length === 1
        ? new Promise<Response>((resolve) => {
            releaseToken = resolve;
          })
        : Promise.resolve(
            String(input) === '/api/csrf-token'
              ? jsonResponse({ token: 'new-session-token' })
              : jsonResponse({ ok: true }),
          ),
    );
    vi.stubGlobal('fetch', fetchMock);

    const warming = warmCsrfToken();
    resetCsrfToken();
    releaseToken(jsonResponse({ token: 'old-session-token' }));
    await warming;
    await apiPut('/api/login', {});

    expect(fetchMock).toHaveBeenLastCalledWith(
      '/api/login',
      expect.objectContaining({
        headers: expect.objectContaining({
          'X-CSRF-Token': 'new-session-token',
        }),
      }),
    );
  });

  it('fails on a CSRF token error instead of sending "undefined"', async () => {
    const fetchMock = vi.fn(() =>
      Promise.resolve(
        new Response(JSON.stringify({ error: 'boom' }), { status: 500 }),
      ),
    );
    vi.stubGlobal('fetch', fetchMock);

    await expect(
      apiPut('/api/anketas/1/private-notes', {}),
    ).rejects.toMatchObject({
      status: 500,
    });
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });
});

describe('api/client connection tracking (GitHub issue #242)', () => {
  beforeEach(() => {
    resetCsrfToken();
    // Fake timers: nothing here waits for the probe or for "Back online".
    vi.useFakeTimers();
  });

  afterEach(() => {
    // Back to 'online' for the next test, whatever this one left.
    recordOffline();
    recordOnline(connectionEpoch());
    vi.advanceTimersByTime(3000);
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  function respond(status: number, contentType: string, body: string): void {
    vi.stubGlobal(
      'fetch',
      vi.fn(() =>
        Promise.resolve(
          new Response(body, {
            status,
            headers: { 'Content-Type': contentType },
          }),
        ),
      ),
    );
  }

  function fail(error: unknown): void {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => Promise.reject(error as Error)),
    );
  }

  it('goes offline on a network error', async () => {
    fail(new TypeError('Failed to fetch'));
    await expect(apiGet('/api/anketas')).rejects.toThrow(TypeError);
    expect(connectionState.status).toBe('offline');
  });

  it('goes offline on a timeout', async () => {
    fail(new DOMException('timed out', 'TimeoutError'));
    await expect(apiGet('/api/anketas')).rejects.toThrow('timed out');
    expect(connectionState.status).toBe('offline');
  });

  it("stays online on the caller's own abort", async () => {
    fail(new DOMException('aborted', 'AbortError'));
    await expect(apiGet('/api/anketas')).rejects.toThrow('aborted');
    expect(connectionState.status).toBe('online');
  });

  it('goes offline when the CSRF token request fails, before the write is sent', async () => {
    fail(new TypeError('Failed to fetch'));
    await expect(apiPut('/api/anketas/1/draft', {})).rejects.toThrow(TypeError);
    expect(connectionState.status).toBe('offline');
  });

  it.each([502, 504])('goes offline on a %i', async (status) => {
    respond(status, 'application/json', '{"error":"x"}');
    await expect(apiGet('/api/anketas')).rejects.toThrow();
    expect(connectionState.status).toBe('offline');
  });

  it.each([503, 521, 523])(
    "goes offline on a proxy's %i page",
    async (status) => {
      respond(status, 'text/html', '<html></html>');
      await expect(apiGet('/api/anketas')).rejects.toThrow();
      expect(connectionState.status).toBe('offline');
    },
  );

  it("stays online on the app's own 503", async () => {
    respond(503, 'application/json', '{"error":"Billing is not configured."}');
    await expect(apiGet('/api/anketas')).rejects.toThrow(
      'Billing is not configured.',
    );
    expect(connectionState.status).toBe('online');
  });

  it.each([401, 403, 404, 409, 500])(
    'reconnects on a %i: the app answered',
    async (status) => {
      recordOffline();
      respond(status, 'application/json', '{"error":"x"}');
      await expect(apiGet('/api/anketas')).rejects.toThrow();
      expect(connectionState.status).toBe('reconnected');
    },
  );

  it('reconnects on a successful request', async () => {
    recordOffline();
    respond(200, 'application/json', '{}');
    await apiGet('/api/anketas');
    expect(connectionState.status).toBe('reconnected');
  });

  it('stays offline on the answer to a request sent before the connection was lost', async () => {
    let answer: (response: Response) => void = () => {};
    vi.stubGlobal(
      'fetch',
      vi.fn(() => new Promise<Response>((resolve) => (answer = resolve))),
    );
    const request = apiGet('/api/anketas');
    recordOffline();

    answer(jsonResponse({}));
    await request;

    expect(connectionState.status).toBe('offline');
  });

  it('stays online when a request that hung through a whole outage fails after it', async () => {
    let fail: (error: Error) => void = () => {};
    vi.stubGlobal(
      'fetch',
      vi.fn(() => new Promise<Response>((_resolve, reject) => (fail = reject))),
    );
    const request = apiGet('/api/anketas');
    recordOffline();
    recordOnline(connectionEpoch());

    fail(new DOMException('timed out', 'TimeoutError'));
    await expect(request).rejects.toThrow('timed out');

    expect(connectionState.status).toBe('reconnected');
  });

  it('goes offline when the connection is lost while the body is read', async () => {
    const response = new Response('{}', { status: 200 });
    vi.spyOn(response, 'json').mockRejectedValue(
      new DOMException('timed out', 'TimeoutError'),
    );
    vi.stubGlobal(
      'fetch',
      vi.fn(() => Promise.resolve(response)),
    );
    await expect(apiGet('/api/anketas')).rejects.toThrow('timed out');
    expect(connectionState.status).toBe('offline');
  });

  it.each([
    ['is not JSON', new SyntaxError('Unexpected token <')],
    [
      "was dropped by the caller's own abort",
      new DOMException('x', 'AbortError'),
    ],
  ])('stays online when the body %s', async (_name, error) => {
    const response = new Response('<html>', { status: 200 });
    vi.spyOn(response, 'json').mockRejectedValue(error);
    vi.stubGlobal(
      'fetch',
      vi.fn(() => Promise.resolve(response)),
    );
    await expect(apiGet('/api/anketas')).rejects.toThrow();
    expect(connectionState.status).toBe('online');
  });

  it.each([
    ["a captive portal's page", 200, 'text/html'],
    ["a proxy's plain 404", 404, 'text/plain'],
  ])('stays offline on %s: not the app', async (_name, status, contentType) => {
    recordOffline();
    respond(status, contentType, 'x');
    await expect(apiGet('/api/anketas')).rejects.toThrow();
    expect(connectionState.status).toBe('offline');
  });

  it.each([404, 500])(
    "stays online on a %i page that isn't the app's",
    async (status) => {
      respond(status, 'text/plain', 'x');
      await expect(apiGet('/api/anketas')).rejects.toThrow();
      expect(connectionState.status).toBe('online');
    },
  );

  it("reconnects on API Platform's JSON-LD", async () => {
    recordOffline();
    respond(200, 'application/ld+json; charset=utf-8', '[]');
    await apiGet('/api/users');
    expect(connectionState.status).toBe('reconnected');
  });

  it('goes offline when the connection is lost while an error body is read', async () => {
    const response = new Response('{}', {
      status: 500,
      headers: { 'Content-Type': 'application/json' },
    });
    vi.spyOn(response, 'json').mockRejectedValue(
      new TypeError('network error'),
    );
    vi.stubGlobal(
      'fetch',
      vi.fn(() => Promise.resolve(response)),
    );
    await expect(apiGet('/api/anketas')).rejects.toThrow();
    expect(connectionState.status).toBe('offline');
  });

  it('stays offline on a gateway error while offline', async () => {
    recordOffline();
    respond(502, 'text/html', '');
    await expect(apiGet('/api/anketas')).rejects.toThrow();
    expect(connectionState.status).toBe('offline');
  });
});
