/**
 * Whether the app can reach its server (GitHub issue #242), for the banner in
 * design/ConnectionBanner.svelte and for retrying on reconnect what a page
 * already keeps locally.
 *
 * One status over the reachable states, not independent booleans
 * (docs/architecture-invariants.md §2):
 *
 *   online       --request failed / `offline` event-->        offline
 *   offline      --a request or the probe got an answer-->    reconnected
 *   reconnected  --3s-->                                      online
 *   reconnected  --request failed / `offline` event-->        offline
 *
 * "Failed" is a request that never got an answer from the app: a network
 * error, a timeout, or a reverse proxy's 502/503/504 (api/client.ts reports
 * all three). The browser's own `online` event is never trusted as
 * "connected": it only means a network interface is up. `navigator.onLine`
 * isn't read at all.
 */
export type ConnectionStatus = 'online' | 'offline' | 'reconnected';

/** How long "Back online" stays up. */
const RECONNECTED_MS = 3000;
/**
 * The first probe waits, so one failed request doesn't flash the banner on
 * and off within the same second.
 */
const FIRST_PROBE_DELAY_MS = 5000;
const PROBE_INTERVAL_MS = 10_000;
/** Spreads the tabs of a whole company probing a server that just restarted. */
const PROBE_JITTER_MS = 1000;
const PROBE_TIMEOUT_MS = 5000;

/**
 * Starts online whatever `navigator.onLine` says: a page that loaded was
 * served by the app a moment ago, and some systems report no network with a
 * working one (a VPN's virtual adapter).
 */
export const connectionState = $state<{ status: ConnectionStatus }>({
  status: 'online',
});

let reconnectListeners: (() => void)[] = [];
/**
 * Bumped each time the connection is lost and each time it comes back. A
 * request remembers the value it was sent at (connectionEpoch()), and its
 * outcome only counts if nothing changed since: an answer already on its way
 * when the network went (the `offline` event comes first) doesn't prove the
 * connection is back, and a request that hung through a whole outage and
 * times out after it doesn't mean it's lost again.
 */
let epoch = 0;
let reconnectedTimer: ReturnType<typeof setTimeout> | undefined;
let probeTimer: ReturnType<typeof setTimeout> | undefined;
/**
 * Names the latest scheduled or running probe. A probe whose token is no
 * longer this one was replaced or cancelled while its request was in flight,
 * and its answer is ignored.
 */
let probeToken = 0;

function tabHidden(): boolean {
  return typeof document !== 'undefined' && document.hidden;
}

function cancelProbe(): void {
  probeToken += 1;
  clearTimeout(probeTimer);
  probeTimer = undefined;
}

/** A hidden tab doesn't probe; becoming visible again probes at once. */
function scheduleProbe(delayMs: number): void {
  cancelProbe();
  if (tabHidden()) return;
  const token = probeToken;
  probeTimer = setTimeout(() => void runProbe(token), delayMs);
}

function probeNow(): void {
  cancelProbe();
  void runProbe(probeToken);
}

/**
 * GET /health is unauthenticated and touches no data. A plain `fetch`, not
 * api/client.ts: that module reports to this one. An answer only counts if
 * it's the app's own: a proxy's error page for a backend that is still down
 * mustn't read as "back online".
 */
async function serverAnswers(): Promise<boolean> {
  try {
    const response = await fetch('/health', {
      cache: 'no-store',
      signal: AbortSignal.timeout(PROBE_TIMEOUT_MS),
    });
    if (!response.ok) return false;
    const body = (await response.json()) as { status?: unknown } | null;
    return body?.status === 'ok';
  } catch {
    return false;
  }
}

async function runProbe(token: number): Promise<void> {
  const answered = await serverAnswers();
  if (token !== probeToken) return;
  if (answered) {
    recordOnline(epoch);
  } else {
    scheduleProbe(
      PROBE_INTERVAL_MS + (Math.random() * 2 - 1) * PROBE_JITTER_MS,
    );
  }
}

/**
 * A request sent at `sentAtEpoch` got no answer from the app; or, with no
 * argument, the browser lost its network.
 */
export function recordOffline(sentAtEpoch: number = epoch): void {
  if (connectionState.status === 'offline' || sentAtEpoch !== epoch) return;
  clearTimeout(reconnectedTimer);
  reconnectedTimer = undefined;
  connectionState.status = 'offline';
  epoch += 1;
  scheduleProbe(FIRST_PROBE_DELAY_MS);
}

/** For a request to remember when it was sent. */
export function connectionEpoch(): number {
  return epoch;
}

/**
 * The app answered a request (with any status of its own, a 401 or 500
 * included) that was sent at `sentAtEpoch`.
 */
export function recordOnline(sentAtEpoch: number): void {
  if (connectionState.status !== 'offline' || sentAtEpoch !== epoch) return;
  epoch += 1;
  cancelProbe();
  connectionState.status = 'reconnected';
  reconnectedTimer = setTimeout(() => {
    reconnectedTimer = undefined;
    connectionState.status = 'online';
  }, RECONNECTED_MS);
  // The list is replaced, never mutated, on (un)subscribing, so a listener
  // that unsubscribes another while these run doesn't disturb the loop.
  // One that throws mustn't fail the request that brought the connection
  // back, or keep the others from running.
  for (const listener of reconnectListeners) {
    try {
      listener();
    } catch (error) {
      console.error(error);
    }
  }
}

/**
 * Calls `listener` each time the connection comes back (offline ->
 * reconnected), for a page to retry what it keeps locally. Returns the
 * unsubscribe function.
 */
export function onReconnect(listener: () => void): () => void {
  reconnectListeners = [...reconnectListeners, listener];
  return () => {
    reconnectListeners = reconnectListeners.filter((it) => it !== listener);
  };
}

/**
 * The browser's signals, wired once by App.svelte. `offline` is trusted;
 * `online` and a tab becoming visible only prompt a probe.
 */
export function initConnectionListeners(): void {
  window.addEventListener('offline', () => recordOffline());
  window.addEventListener('online', () => {
    if (connectionState.status === 'offline') probeNow();
  });
  document.addEventListener('visibilitychange', () => {
    if (connectionState.status !== 'offline') return;
    if (document.hidden) cancelProbe();
    else probeNow();
  });
  // A page restored from the back/forward cache: leaving it failed its
  // requests in flight (Firefox reports that as a network error).
  window.addEventListener('pageshow', (event) => {
    if (event.persisted && connectionState.status === 'offline') probeNow();
  });
}
