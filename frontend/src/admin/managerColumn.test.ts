import { describe, expect, it } from 'vitest';
import { hasNoManager, managerChoices, type OrgUser } from './managerColumn';

function user(id: string, overrides: Partial<OrgUser> = {}): OrgUser {
  return {
    id,
    email: `${id}@example.com`,
    displayName: '',
    isBlocked: false,
    deletedAt: null,
    managerId: null,
    ...overrides,
  };
}

const ids = (users: OrgUser[]): string[] => users.map((u) => u.id);

describe('managerChoices', () => {
  it('offers everyone but the row itself', () => {
    const anna = user('anna');
    const users = [anna, user('boris'), user('clara')];

    expect(ids(managerChoices(users, anna))).toEqual(['boris', 'clara']);
  });

  it('leaves out blocked and deleted people', () => {
    const anna = user('anna');
    const users = [
      anna,
      user('boris', { isBlocked: true }),
      user('clara', {
        isBlocked: true,
        deletedAt: '2026-10-01T00:00:00+00:00',
      }),
      user('dana'),
    ];

    expect(ids(managerChoices(users, anna))).toEqual(['dana']);
  });

  it('keeps the current manager even when blocked', () => {
    const anna = user('anna', { managerId: 'boris' });
    const users = [anna, user('boris', { isBlocked: true }), user('clara')];

    expect(ids(managerChoices(users, anna))).toEqual(['boris', 'clara']);
  });

  it('keeps a current manager who is a deleted account (a link the deletion race left)', () => {
    const anna = user('anna', { managerId: 'boris' });
    const users = [
      anna,
      user('boris', {
        isBlocked: true,
        deletedAt: '2026-10-01T00:00:00+00:00',
      }),
    ];

    expect(ids(managerChoices(users, anna))).toEqual(['boris']);
  });

  it("does not hide the row's own reports: the server answers for cycles", () => {
    const anna = user('anna');
    const users = [anna, user('boris', { managerId: 'anna' })];

    expect(ids(managerChoices(users, anna))).toEqual(['boris']);
  });

  it('sorts by what the option shows, name before bare email', () => {
    const anna = user('anna');
    const users = [
      anna,
      user('zed'),
      user('x1', { displayName: 'Maria Lopez' }),
      user('x2', { displayName: 'Boris Ivanov' }),
    ];

    expect(ids(managerChoices(users, anna))).toEqual(['x2', 'x1', 'zed']);
  });

  it('does not reorder the list it was given', () => {
    const users = [user('zed'), user('anna'), user('boris')];

    managerChoices(users, users[0]!);

    expect(ids(users)).toEqual(['zed', 'anna', 'boris']);
  });
});

describe('hasNoManager', () => {
  const byId = (users: OrgUser[]) => new Map(users.map((u) => [u.id, u]));

  it('is true for a live account with no manager', () => {
    expect(hasNoManager(user('anna'), byId([]))).toBe(true);
    expect(hasNoManager(user('anna', { isBlocked: true }), byId([]))).toBe(
      true,
    );
  });

  it('is false once a manager is set, a blocked one included', () => {
    const anna = user('anna', { managerId: 'boris' });

    expect(hasNoManager(anna, byId([user('boris')]))).toBe(false);
    expect(hasNoManager(anna, byId([user('boris', { isBlocked: true })]))).toBe(
      false,
    );
  });

  it('is true when the link points at a deleted or unknown account', () => {
    const anna = user('anna', { managerId: 'boris' });
    const deleted = user('boris', {
      isBlocked: true,
      deletedAt: '2026-10-01T00:00:00+00:00',
    });

    expect(hasNoManager(anna, byId([deleted]))).toBe(true);
    expect(hasNoManager(anna, byId([]))).toBe(true);
  });

  it('is false for a deleted account', () => {
    expect(
      hasNoManager(
        user('anna', { deletedAt: '2026-10-01T00:00:00+00:00' }),
        byId([]),
      ),
    ).toBe(false);
  });
});
