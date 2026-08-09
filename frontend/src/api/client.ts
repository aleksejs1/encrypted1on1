import { get } from 'svelte/store';
import { locale } from 'svelte-i18n';

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

let csrfToken: string | null = null;

async function getCsrfToken(): Promise<string> {
  if (csrfToken) return csrfToken;
  const response = await fetch('/api/csrf-token', { credentials: 'include' });
  const data = (await response.json()) as { token: string };
  csrfToken = data.token;
  return csrfToken;
}

async function toApiError(response: Response): Promise<ApiError> {
  try {
    const data = (await response.json()) as { error?: string };
    return new ApiError(response.status, data.error ?? response.statusText, data);
  } catch {
    return new ApiError(response.status, response.statusText);
  }
}

export async function apiGet<T>(path: string): Promise<T> {
  const response = await fetch(path, {
    credentials: 'include',
    headers: { 'X-Locale': get(locale) ?? 'en' },
  });
  if (!response.ok) {
    throw await toApiError(response);
  }
  return response.json() as Promise<T>;
}

/** CSRF-protected, per the spec — the token is fetched once and cached for the tab's lifetime. */
async function send<T>(method: 'POST' | 'PUT', path: string, body: unknown): Promise<T> {
  const token = await getCsrfToken();
  const response = await fetch(path, {
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
  });
  if (!response.ok) {
    throw await toApiError(response);
  }
  return response.json() as Promise<T>;
}

export function apiPost<T>(path: string, body: unknown): Promise<T> {
  return send<T>('POST', path, body);
}

export function apiPut<T>(path: string, body: unknown): Promise<T> {
  return send<T>('PUT', path, body);
}
