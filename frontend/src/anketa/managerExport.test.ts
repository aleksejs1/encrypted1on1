import { describe, expect, it } from 'vitest';
import { ApiError } from '../api/client';
import { managerForExport } from './managerExport';

const jordan = {
  id: 'u1',
  email: 'jordan@example.com',
  displayName: 'Jordan Lee',
};

const answering = (answer: unknown) => () => Promise.resolve(answer);
const failing = (error: unknown) => () => Promise.reject(error);

describe('managerForExport', () => {
  it('exports my manager by email and name, without the id', async () => {
    expect(
      await managerForExport(answering({ manager: jordan, directReports: [] })),
    ).toEqual({
      manager: { email: 'jordan@example.com', displayName: 'Jordan Lee' },
    });
  });

  it('exports null when I have no manager', async () => {
    expect(
      await managerForExport(answering({ manager: null, directReports: [] })),
    ).toEqual({ manager: null });
  });

  it('never exports my direct reports, or anything else of the answer', async () => {
    const exported = await managerForExport(
      answering({
        manager: { ...jordan, somethingNew: 'x' },
        directReports: [
          { id: 'u2', email: 'alex@example.com', displayName: 'Alex Kim' },
        ],
      }),
    );

    expect(JSON.stringify(exported)).not.toContain('alex');
    expect(Object.keys(exported)).toEqual(['manager']);
    expect(Object.keys(exported.manager ?? {})).toEqual([
      'email',
      'displayName',
    ]);
  });

  it.each([
    ['a lost connection', new TypeError('Failed to fetch')],
    ['a server error', new ApiError(500, 'Internal Server Error')],
    ['a proxy with no backend behind it', new ApiError(502, 'Bad Gateway')],
    ['a backend without the endpoint', new ApiError(404, 'Not Found')],
    ['a rate limit', new ApiError(429, 'Too Many Requests')],
  ])(
    'marks the manager unavailable, with no manager key, for %s',
    async (_label, error) => {
      const exported = await managerForExport(failing(error));

      expect(exported).toEqual({ managerUnavailable: true });
      expect('manager' in exported).toBe(false);
    },
  );

  it.each([401, 403])(
    'fails on a %i: the session is gone, so is the export',
    async (status) => {
      const error = new ApiError(status, 'Refused');

      await expect(managerForExport(failing(error))).rejects.toBe(error);
    },
  );

  it.each([
    ['not an object', 'nope'],
    ['null', null],
    ['no manager field', { directReports: [] }],
    ['a manager without an email', { manager: { displayName: 'Jordan' } }],
    ['a manager that is not an object', { manager: 'jordan' }],
  ])('marks the manager unavailable for %s', async (_label, answer) => {
    expect(await managerForExport(answering(answer))).toEqual({
      managerUnavailable: true,
    });
  });
});
