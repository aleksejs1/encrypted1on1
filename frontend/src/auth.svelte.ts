import { apiGet, ApiError } from './api/client';

export const authState = $state<{ checked: boolean; authenticated: boolean }>({
  checked: false,
  authenticated: false,
});

export async function checkAuth(): Promise<void> {
  try {
    await apiGet('/api/me');
    authState.authenticated = true;
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) {
      authState.authenticated = false;
    } else {
      throw error;
    }
  } finally {
    authState.checked = true;
  }
}

/** Called by Login/Activate after a successful login — avoids a redundant round-trip to /api/me. */
export function markAuthenticated(): void {
  authState.authenticated = true;
  authState.checked = true;
}
