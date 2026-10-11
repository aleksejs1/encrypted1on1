/**
 * The admin user table's "Manager" column (GitHub issue #267, part of #265): which
 * people the select of the row being edited offers, and which rows the "no manager" filter keeps. The
 * server decides whether an assignment is allowed (App\Org\OrgStructure); nothing
 * here tries to predict that, cycles included.
 */
import { nameWithEmail } from '../userDisplay';

export interface OrgUser {
  id: string;
  email: string;
  displayName: string;
  isBlocked: boolean;
  deletedAt: string | null;
  managerId: string | null;
}

/**
 * Everyone who can be given new reports (not blocked, not deleted), minus the row's
 * own user, plus the row's current manager whatever their state: a blocked manager is
 * still the manager, and the select has to be able to show them.
 */
export function managerChoices(users: OrgUser[], row: OrgUser): OrgUser[] {
  return users
    .filter(
      (user) =>
        user.id !== row.id &&
        (user.id === row.managerId || (!user.isBlocked && !user.deletedAt)),
    )
    .sort((a, b) =>
      nameWithEmail(a.displayName, a.email).localeCompare(
        nameWithEmail(b.displayName, b.email),
      ),
    );
}

/**
 * The top of the tree, a new account, and a report of a manager whose account was
 * deleted all look the same here. The last includes a link still pointing at a
 * deleted account, which an assignment racing the deletion can leave behind.
 * Deleted accounts themselves aren't people to find a manager for.
 */
export function hasNoManager(
  user: OrgUser,
  usersById: ReadonlyMap<string, OrgUser>,
): boolean {
  if (user.deletedAt) return false;
  const manager = user.managerId ? usersById.get(user.managerId) : undefined;
  return !manager || manager.deletedAt !== null;
}
