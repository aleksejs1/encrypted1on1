import {
  afterEach,
  beforeEach,
  describe,
  expect,
  it,
  vi,
  type Mock,
} from 'vitest';

type Module = typeof import('./connectionState.svelte');

/** The browser pieces the module listens to, as far as these tests need them. */
function fakeBrowser(options: { onLine?: boolean } = {}) {
  const windowListeners = new Map<string, (event?: unknown) => void>();
  const documentListeners = new Map<string, () => void>();
  const doc = {
    hidden: false,
    addEventListener: (type: string, listener: () => void) =>
      documentListeners.set(type, listener),
  };
  vi.stubGlobal('window', {
    addEventListener: (type: string, listener: (event?: unknown) => void) =>
      windowListeners.set(type, listener),
  });
  vi.stubGlobal('document', doc);
  vi.stubGlobal('navigator', { onLine: options.onLine });
  return {
    fire: (type: 'online' | 'offline') => windowListeners.get(type)?.(),
    pageShow: (persisted: boolean) =>
      windowListeners.get('pageshow')?.({ persisted }),
    setHidden: (hidden: boolean) => {
      doc.hidden = hidden;
      documentListeners.get('visibilitychange')?.();
    },
  };
}

function health(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

describe('connectionState', () => {
  let fetchMock: Mock<(path: string) => Promise<Response>>;

  async function load(): Promise<Module> {
    vi.resetModules();
    return import('./connectionState.svelte');
  }

  beforeEach(() => {
    vi.useFakeTimers();
    // No jitter: the probe interval is exactly 10s.
    vi.spyOn(Math, 'random').mockReturnValue(0.5);
    fetchMock = vi.fn<(path: string) => Promise<Response>>(() =>
      Promise.reject(new TypeError('Failed to fetch')),
    );
    vi.stubGlobal('fetch', fetchMock);
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  it('starts online, whatever the browser reports', async () => {
    fakeBrowser({ onLine: false });
    expect((await load()).connectionState.status).toBe('online');
  });

  describe('going offline', () => {
    it('happens on a failed request', async () => {
      fakeBrowser();
      const connection = await load();
      connection.recordOffline();
      expect(connection.connectionState.status).toBe('offline');
    });

    it("happens on the browser's offline event", async () => {
      const browser = fakeBrowser();
      const connection = await load();
      connection.initConnectionListeners();
      browser.fire('offline');
      expect(connection.connectionState.status).toBe('offline');
    });

    it("doesn't restart the probe when it's already offline", async () => {
      fakeBrowser();
      const connection = await load();
      connection.recordOffline();
      await vi.advanceTimersByTimeAsync(4000);
      connection.recordOffline();
      await vi.advanceTimersByTimeAsync(1000);
      expect(fetchMock).toHaveBeenCalledTimes(1);
    });
  });

  describe('coming back', () => {
    it('shows "reconnected" for 3 seconds on an answered request, then online', async () => {
      fakeBrowser();
      const connection = await load();
      connection.recordOffline();

      connection.recordOnline(connection.connectionEpoch());
      expect(connection.connectionState.status).toBe('reconnected');

      await vi.advanceTimersByTimeAsync(2999);
      expect(connection.connectionState.status).toBe('reconnected');
      await vi.advanceTimersByTimeAsync(1);
      expect(connection.connectionState.status).toBe('online');
    });

    it('stops probing once back', async () => {
      fakeBrowser();
      const connection = await load();
      connection.recordOffline();
      connection.recordOnline(connection.connectionEpoch());
      await vi.advanceTimersByTimeAsync(60_000);
      expect(fetchMock).not.toHaveBeenCalled();
    });

    it('is a no-op for an answered request while online', async () => {
      fakeBrowser();
      const connection = await load();
      const listener = vi.fn();
      connection.onReconnect(listener);

      connection.recordOnline(connection.connectionEpoch());

      expect(connection.connectionState.status).toBe('online');
      expect(listener).not.toHaveBeenCalled();
    });

    it("doesn't restart the 3 seconds on further answered requests", async () => {
      fakeBrowser();
      const connection = await load();
      connection.recordOffline();
      connection.recordOnline(connection.connectionEpoch());
      await vi.advanceTimersByTimeAsync(2000);
      connection.recordOnline(connection.connectionEpoch());
      await vi.advanceTimersByTimeAsync(1000);
      expect(connection.connectionState.status).toBe('online');
    });

    it('goes straight back to offline on a failure during "reconnected", and the old 3 seconds never fire', async () => {
      fakeBrowser();
      const connection = await load();
      connection.recordOffline();
      connection.recordOnline(connection.connectionEpoch());
      await vi.advanceTimersByTimeAsync(1000);

      connection.recordOffline();
      await vi.advanceTimersByTimeAsync(3000);

      expect(connection.connectionState.status).toBe('offline');
    });
  });

  describe('an answer to a request sent before the connection was lost', () => {
    it("doesn't count as being back", async () => {
      fakeBrowser();
      const connection = await load();
      const sentAt = connection.connectionEpoch();
      connection.recordOffline();

      connection.recordOnline(sentAt);

      expect(connection.connectionState.status).toBe('offline');
    });

    it("doesn't count when it was sent during an earlier loss either", async () => {
      fakeBrowser();
      const connection = await load();
      connection.recordOffline();
      const sentAt = connection.connectionEpoch();
      connection.recordOnline(sentAt);
      connection.recordOffline();

      connection.recordOnline(sentAt);

      expect(connection.connectionState.status).toBe('offline');
    });
  });

  describe('a failure of a request sent before the latest change', () => {
    it("doesn't count as a new loss once the connection is back", async () => {
      fakeBrowser();
      const connection = await load();
      const sentAt = connection.connectionEpoch();
      connection.recordOffline();
      connection.recordOnline(connection.connectionEpoch());

      connection.recordOffline(sentAt);

      expect(connection.connectionState.status).toBe('reconnected');
    });

    it('counts when nothing changed since it was sent', async () => {
      fakeBrowser();
      const connection = await load();
      connection.recordOffline();
      connection.recordOnline(connection.connectionEpoch());

      connection.recordOffline(connection.connectionEpoch());

      expect(connection.connectionState.status).toBe('offline');
    });
  });

  describe('reconnect listeners', () => {
    it('all run, and the caller is not failed, when one throws', async () => {
      fakeBrowser();
      const connection = await load();
      const logged = vi.spyOn(console, 'error').mockImplementation(() => {});
      const second = vi.fn();
      connection.onReconnect(() => {
        throw new Error('listener failed');
      });
      connection.onReconnect(second);

      connection.recordOffline();
      expect(() =>
        connection.recordOnline(connection.connectionEpoch()),
      ).not.toThrow();

      expect(second).toHaveBeenCalledTimes(1);
      expect(logged).toHaveBeenCalledTimes(1);
    });

    it('run once per reconnect, not on the way back to online', async () => {
      fakeBrowser();
      const connection = await load();
      const listener = vi.fn();
      connection.onReconnect(listener);

      connection.recordOffline();
      expect(listener).not.toHaveBeenCalled();
      connection.recordOnline(connection.connectionEpoch());
      expect(listener).toHaveBeenCalledTimes(1);
      await vi.advanceTimersByTimeAsync(3000);
      expect(listener).toHaveBeenCalledTimes(1);

      connection.recordOffline();
      connection.recordOnline(connection.connectionEpoch());
      expect(listener).toHaveBeenCalledTimes(2);
    });

    it('stop running once unsubscribed', async () => {
      fakeBrowser();
      const connection = await load();
      const listener = vi.fn();
      const other = vi.fn();
      const unsubscribe = connection.onReconnect(listener);
      connection.onReconnect(other);
      unsubscribe();

      connection.recordOffline();
      connection.recordOnline(connection.connectionEpoch());

      expect(listener).not.toHaveBeenCalled();
      expect(other).toHaveBeenCalledTimes(1);
    });

    it('all run even when one unsubscribes another while they run', async () => {
      fakeBrowser();
      const connection = await load();
      const second = vi.fn();
      let unsubscribeSecond = () => {};
      connection.onReconnect(() => unsubscribeSecond());
      unsubscribeSecond = connection.onReconnect(second);

      connection.recordOffline();
      connection.recordOnline(connection.connectionEpoch());

      expect(second).toHaveBeenCalledTimes(1);
    });
  });

  describe('the probe', () => {
    it('first runs after 5 seconds, then every 10 while the server stays away', async () => {
      fakeBrowser();
      const connection = await load();
      connection.recordOffline();

      await vi.advanceTimersByTimeAsync(4999);
      expect(fetchMock).not.toHaveBeenCalled();
      await vi.advanceTimersByTimeAsync(1);
      expect(fetchMock).toHaveBeenCalledTimes(1);
      expect(fetchMock.mock.calls[0][0]).toBe('/health');

      await vi.advanceTimersByTimeAsync(10_000);
      expect(fetchMock).toHaveBeenCalledTimes(2);
      await vi.advanceTimersByTimeAsync(10_000);
      expect(fetchMock).toHaveBeenCalledTimes(3);
      expect(connection.connectionState.status).toBe('offline');
    });

    it("reconnects on the app's own answer", async () => {
      fakeBrowser();
      const connection = await load();
      const listener = vi.fn();
      connection.onReconnect(listener);
      fetchMock.mockResolvedValue(health(200, { status: 'ok' }));

      connection.recordOffline();
      await vi.advanceTimersByTimeAsync(5000);

      expect(connection.connectionState.status).toBe('reconnected');
      expect(listener).toHaveBeenCalledTimes(1);
    });

    it.each([
      ['a 502 from a proxy', () => health(502, { status: 'ok' })],
      ['a 200 that is not the health answer', () => health(200, { ok: true })],
      [
        'a 200 with an HTML page (a captive portal)',
        () => new Response('<html></html>', { status: 200 }),
      ],
      ['a 200 with a null body', () => health(200, null)],
    ])('stays offline on %s', async (_name, response) => {
      fakeBrowser();
      const connection = await load();
      fetchMock.mockImplementation(() => Promise.resolve(response()));

      connection.recordOffline();
      await vi.advanceTimersByTimeAsync(5000);

      expect(connection.connectionState.status).toBe('offline');
      // And keeps probing.
      await vi.advanceTimersByTimeAsync(10_000);
      expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it("runs at once on the browser's online event, which alone proves nothing", async () => {
      const browser = fakeBrowser();
      const connection = await load();
      connection.initConnectionListeners();
      browser.fire('offline');

      browser.fire('online');
      expect(connection.connectionState.status).toBe('offline');
      expect(fetchMock).toHaveBeenCalledTimes(1);

      // The 5-second first probe was replaced, not left running beside it.
      await vi.advanceTimersByTimeAsync(5000);
      expect(fetchMock).toHaveBeenCalledTimes(1);
      await vi.advanceTimersByTimeAsync(5000);
      expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('runs at once on a page restored from the back/forward cache, not on an ordinary load', async () => {
      const browser = fakeBrowser();
      const connection = await load();
      connection.initConnectionListeners();
      connection.recordOffline();

      browser.pageShow(false);
      expect(fetchMock).not.toHaveBeenCalled();
      browser.pageShow(true);
      expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it("ignores the browser's online event while online", async () => {
      const browser = fakeBrowser();
      const connection = await load();
      connection.initConnectionListeners();
      browser.fire('online');
      expect(fetchMock).not.toHaveBeenCalled();
    });

    it('pauses in a hidden tab and runs at once when the tab is visible again', async () => {
      const browser = fakeBrowser();
      const connection = await load();
      connection.initConnectionListeners();
      connection.recordOffline();

      browser.setHidden(true);
      await vi.advanceTimersByTimeAsync(60_000);
      expect(fetchMock).not.toHaveBeenCalled();

      browser.setHidden(false);
      expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it("doesn't start in a tab that is hidden when the connection is lost", async () => {
      const browser = fakeBrowser();
      const connection = await load();
      connection.initConnectionListeners();
      browser.setHidden(true);

      connection.recordOffline();
      await vi.advanceTimersByTimeAsync(60_000);

      expect(fetchMock).not.toHaveBeenCalled();
    });

    it("doesn't probe on a visibility change while online", async () => {
      const browser = fakeBrowser();
      const connection = await load();
      connection.initConnectionListeners();
      browser.setHidden(true);
      browser.setHidden(false);
      expect(fetchMock).not.toHaveBeenCalled();
    });

    it('ignores an answer that arrives after the connection was lost again', async () => {
      fakeBrowser();
      const connection = await load();
      let answer: (response: Response) => void = () => {};
      fetchMock.mockImplementationOnce(
        () => new Promise<Response>((resolve) => (answer = resolve)),
      );

      connection.recordOffline();
      await vi.advanceTimersByTimeAsync(5000);
      // In flight. Meanwhile a request is answered, then another one fails.
      connection.recordOnline(connection.connectionEpoch());
      connection.recordOffline();

      answer(health(200, { status: 'ok' }));
      await vi.advanceTimersByTimeAsync(0);

      expect(connection.connectionState.status).toBe('offline');
      // The new offline period has its own probe.
      await vi.advanceTimersByTimeAsync(5000);
      expect(fetchMock).toHaveBeenCalledTimes(2);
    });
  });
});
