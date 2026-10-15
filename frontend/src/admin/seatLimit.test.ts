import { describe, expect, it } from 'vitest';
import { ApiError } from '../api/client';
import { repoFile } from '../testRepoFile';
import { inviteError, SEAT_LIMIT_CODE, seatLimitStep } from './seatLimit';

describe('seatLimitStep', () => {
  it('sends an admin in the Cloud deployment to the Plan page', () => {
    expect(seatLimitStep({ isAdmin: true, cloudMode: true })).toBe('view-plan');
  });

  it('has anyone else in the Cloud deployment ask an admin', () => {
    expect(seatLimitStep({ isAdmin: false, cloudMode: true })).toBe(
      'ask-admin',
    );
  });

  it('adds nothing on a self-hosted instance, admin or not', () => {
    expect(seatLimitStep({ isAdmin: true, cloudMode: false })).toBe('none');
    expect(seatLimitStep({ isAdmin: false, cloudMode: false })).toBe('none');
  });
});

describe('inviteError', () => {
  const FALLBACK = 'Could not send.';

  it('uses the code InviteController sends', () => {
    const php = repoFile('backend/src/Controller/InviteController.php');
    expect(php).toContain(`'code' => '${SEAT_LIMIT_CODE}'`);
  });

  it("keeps the server's message and marks a refusal for want of a seat", () => {
    const error = new ApiError(400, 'No seats left.', {
      error: 'No seats left.',
      code: SEAT_LIMIT_CODE,
    });
    expect(inviteError(error, FALLBACK)).toEqual({
      message: 'No seats left.',
      seatLimit: true,
    });
  });

  it('keeps any other refusal without a next step, by code and not by text', () => {
    const text =
      'This company has reached its seat limit for the current plan.';
    expect(
      inviteError(new ApiError(400, text, { error: text }), FALLBACK),
    ).toEqual({
      message: text,
      seatLimit: false,
    });
    expect(
      inviteError(new ApiError(400, 'x', { code: 'invalid_token' }), FALLBACK)
        .seatLimit,
    ).toBe(false);
    expect(inviteError(new ApiError(500, 'x'), FALLBACK).seatLimit).toBe(false);
    expect(
      inviteError(new ApiError(400, 'x', SEAT_LIMIT_CODE), FALLBACK).seatLimit,
    ).toBe(false);
  });

  it("uses the fallback for an error that is not the server's", () => {
    expect(inviteError(new Error(SEAT_LIMIT_CODE), FALLBACK)).toEqual({
      message: FALLBACK,
      seatLimit: false,
    });
  });
});
