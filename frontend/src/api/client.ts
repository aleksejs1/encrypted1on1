import { get } from 'svelte/store';
import { locale } from 'svelte-i18n';
import {
  connectionEpoch,
  recordOffline,
  recordOnline,
} from '../connectivity/connectionState.svelte';
import { isAbortError } from './abortOnDestroy';

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    /** The full parsed JSON error body, if there was one — e.g. the 409 comments-conflict payload. */
    public readonly body: unknown = null,
  ) {
    super(message);
  }
}

export interface RequestOptions {
  /**
   * Lets a caller abort an in-flight request (e.g. on unmount or when
   * superseded by a newer request). Aborting only stops the client from
   * waiting on the response — for apiPost/apiPut/apiDelete it does *not*
   * guarantee the server never received/applied the request, so only use it
   * to discard a response the caller no longer needs, never to rely on the
   * write itself having been cancelled.
   */
  signal?: AbortSignal;
  /**
   * Lets the request outlive the page (fetch's `keepalive`), for a last save
   * on `pagehide`. Browsers cap a keepalive body at about 64 KB, so callers
   * only set it for a small enough body.
   */
  keepalive?: boolean;
}

/**
 * Who a response is from, as far as its headers tell:
 * - 'gateway': a reverse proxy or CDN answering for a backend it can't reach
 *   (a restart during a deploy). The app never sends 502 or 504, and its own
 *   503s (billing not configured, an invite email that couldn't be sent) are
 *   JSON, which a proxy's error page isn't.
 * - 'app': JSON, as every answer of the app's is, with any status.
 * - 'unknown': anything else (a captive portal's login page, a proxy's plain
 *   404 while the container is recreated). It proves neither that the
 *   connection is lost nor that the app is back.
 */
function answeredBy(response: Response): 'app' | 'gateway' | 'unknown' {
  const { status } = response;
  if (status === 502 || status === 504) return 'gateway';
  const isJson = (response.headers.get('content-type') ?? '').includes('json');
  if (isJson) return 'app';
  // 502 and up: a CDN's own codes for an origin it can't reach (520-530)
  // too. Not a non-JSON 500, which is the backend itself crashing.
  return status >= 502 ? 'gateway' : 'unknown';
}

/**
 * `fetch`, reporting to connectivity/connectionState.svelte.ts whether the
 * app answered (GitHub issue #242). Any status of the app's own, a 401 or a
 * 500 included, means the connection works (see answeredBy()). No answer at all is a network
 * error or a timeout (an `AbortSignal.timeout()` rejects with TimeoutError);
 * a caller's own abort says nothing either way.
 */
async function trackedFetch(
  path: string,
  init: RequestInit,
): Promise<Response> {
  const sentAtEpoch = connectionEpoch();
  let response: Response;
  try {
    response = await fetch(path, init);
  } catch (error) {
    if (!isAbortError(error)) recordOffline(sentAtEpoch);
    throw error;
  }
  const from = answeredBy(response);
  if (from === 'gateway') recordOffline(sentAtEpoch);
  else if (from === 'app') recordOnline(sentAtEpoch);
  return response;
}

/**
 * The body arrives after the headers, and the connection can go in between
 * (or a request's timeout can run out: its signal covers the body too).
 * Invalid JSON is an answer, just not a usable one.
 */
async function readJson<T>(response: Response): Promise<T> {
  const startedAtEpoch = connectionEpoch();
  try {
    return (await response.json()) as T;
  } catch (error) {
    if (!isAbortError(error) && !(error instanceof SyntaxError)) {
      recordOffline(startedAtEpoch);
    }
    throw error;
  }
}

let csrfToken: string | null = null;
/**
 * Bumped by resetCsrfToken(). A token fetch that started before a logout must
 * not cache its token after the reset: it belongs to the old session, and the
 * next state-changing request (logging back in) would fail with a 403.
 */
let csrfEpoch = 0;

async function getCsrfToken(options?: RequestOptions): Promise<string> {
  if (csrfToken) {
    options?.signal?.throwIfAborted();
    return csrfToken;
  }
  const startedEpoch = csrfEpoch;
  const response = await trackedFetch('/api/csrf-token', {
    credentials: 'include',
    signal: options?.signal,
  });
  // An error body has no token: fail, rather than send "undefined" and get a
  // 403 that reads as an ended session.
  if (!response.ok) throw await toApiError(response);
  const data = await readJson<{ token: string }>(response);
  if (startedEpoch === csrfEpoch) csrfToken = data.token;
  return data.token;
}

/**
 * Fetches and caches the CSRF token ahead of time (private notes' panel does
 * on load, GitHub issue #132 §6.3). With a cached token, a PUT issued inside a
 * `pagehide` handler goes out within that task, before the page is gone,
 * instead of waiting on a token fetch that may never complete.
 */
export async function warmCsrfToken(): Promise<void> {
  await getCsrfToken();
}

/**
 * AuthSession::logOut() calls $session->invalidate() server-side, which wipes
 * the session-stored CSRF secret backing whatever token is cached here — the
 * very next state-changing request (e.g. a re-login in the same tab) would
 * otherwise send a now-stale token and get a genuine 403. Must be called
 * wherever logout happens; see auth.svelte.ts's logOut().
 */
export function resetCsrfToken(): void {
  csrfToken = null;
  csrfEpoch += 1;
}

async function toApiError(response: Response): Promise<ApiError> {
  try {
    const data = await readJson<{ error?: string }>(response);
    return new ApiError(
      response.status,
      data.error ?? response.statusText,
      data,
    );
  } catch {
    return new ApiError(response.status, response.statusText);
  }
}

export async function apiGet<T>(
  path: string,
  options?: RequestOptions,
): Promise<T> {
  const response = await trackedFetch(path, {
    credentials: 'include',
    headers: { 'X-Locale': get(locale) ?? 'en' },
    signal: options?.signal,
  });
  if (!response.ok) {
    throw await toApiError(response);
  }
  return readJson<T>(response);
}

/**
 * Walks every page of an API Platform GetCollection resource (this app doesn't
 * configure client-controllable pagination, so every such resource uses the
 * default 30-item page size and the plain `?page=N` convention, not Hydra
 * pagination metadata — matches backend/tests/Functional/UserResourceTest.php's
 * own `fetchAllUserEmails()`). A single unpaginated `apiGet` silently truncates
 * at 30 items once a resource has more rows than that.
 */
export async function apiGetAllPages<T>(
  path: string,
  options?: RequestOptions,
): Promise<T[]> {
  const separator = path.includes('?') ? '&' : '?';
  const results: T[] = [];
  for (let page = 1; ; page += 1) {
    const rows = await apiGet<T[]>(`${path}${separator}page=${page}`, options);
    if (rows.length === 0) break;
    results.push(...rows);
  }
  return results;
}

/** CSRF-protected, per the spec — the token is fetched once and cached for the tab's lifetime. */
async function send<T>(
  method: 'POST' | 'PUT' | 'DELETE',
  path: string,
  body: unknown,
  options?: RequestOptions,
): Promise<T> {
  const token = await getCsrfToken(options);
  const response = await trackedFetch(path, {
    method,
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': token,
      // The active UI language (Phase 6h) — lets the backend translate error
      // messages (Phase 6j) into it, independent of the browser's Accept-Language.
      'X-Locale': get(locale) ?? 'en',
    },
    body: JSON.stringify(body),
    signal: options?.signal,
    keepalive: options?.keepalive,
  });
  if (!response.ok) {
    throw await toApiError(response);
  }
  return readJson<T>(response);
}

export function apiPost<T>(
  path: string,
  body: unknown,
  options?: RequestOptions,
): Promise<T> {
  return send<T>('POST', path, body, options);
}

export function apiPut<T>(
  path: string,
  body: unknown,
  options?: RequestOptions,
): Promise<T> {
  return send<T>('PUT', path, body, options);
}

export function apiDelete<T>(
  path: string,
  body: unknown,
  options?: RequestOptions,
): Promise<T> {
  return send<T>('DELETE', path, body, options);
}
