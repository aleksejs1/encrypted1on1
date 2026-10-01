import { describe, expect, it } from 'vitest';
import { ApiError } from './api/client';
import { linkStateFromError } from './activationLink';

function apiError(status: number, body: unknown): ApiError {
  return new ApiError(status, 'message', body);
}

describe('linkStateFromError', () => {
  it('reads an unknown link', () => {
    expect(
      linkStateFromError(apiError(404, { code: 'invalid_token' })),
    ).toEqual({ kind: 'invalid' });
  });

  it('reads an already activated link', () => {
    expect(
      linkStateFromError(apiError(409, { code: 'already_activated' })),
    ).toEqual({ kind: 'alreadyActive' });
  });

  it.each([
    'available',
    'requested',
    'reissued',
    'signup',
    'create_company',
    'none',
  ] as const)('reads an expired link with renewal %s', (renewal) => {
    expect(
      linkStateFromError(apiError(410, { code: 'expired', renewal })),
    ).toEqual({ kind: 'expired', renewal });
  });

  it('leaves an unknown renewal value to the caller', () => {
    expect(
      linkStateFromError(apiError(410, { code: 'expired', renewal: 'later' })),
    ).toBeNull();
    expect(
      linkStateFromError(apiError(410, { code: 'expired', renewal: 1 })),
    ).toBeNull();
    // Not an own key of the lookup table.
    expect(
      linkStateFromError(
        apiError(410, { code: 'expired', renewal: 'toString' }),
      ),
    ).toBeNull();
  });

  it('leaves a status and code that do not belong together to the caller', () => {
    expect(
      linkStateFromError(apiError(404, { code: 'already_activated' })),
    ).toBeNull();
    expect(
      linkStateFromError(apiError(409, { code: 'not_expired' })),
    ).toBeNull();
  });

  it('leaves the rate limit, a failed send and bodiless errors to the caller', () => {
    expect(
      linkStateFromError(apiError(429, { error: 'Too many requests.' })),
    ).toBeNull();
    expect(
      linkStateFromError(apiError(429, { code: 'already_requested' })),
    ).toBeNull();
    expect(
      linkStateFromError(apiError(503, { code: 'send_failed' })),
    ).toBeNull();
    expect(linkStateFromError(apiError(404, null))).toBeNull();
    expect(linkStateFromError(new Error('offline'))).toBeNull();
  });
});
