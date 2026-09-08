/**
 * Shared shape/label logic between AdminInvites.svelte (company-scoped) and
 * PlatformAdminPanel.svelte's invites section (cross-company) — both mirror
 * InviteController::toPayload()'s return shape (backend/src/Controller/InviteController.php)
 * and need the same three-way sender rendering. The table markup itself stays
 * duplicated between the two (different columns — PlatformAdminPanel adds a company
 * column — and this codebase's own established tolerance for two small, differently-
 * shaped admin tables, e.g. AdminController/PlatformAdminController's own listUsers()
 * mappings), but the branching logic here is real conditional logic worth keeping in
 * exactly one place.
 */

interface InvitedBy {
  name: string;
  email: string;
}

export interface Invite {
  id: string;
  email: string;
  invitedBy: InvitedBy | { deleted: true } | null;
  createdAt: string;
  expiresAt: string;
  acceptedAt: string | null;
  status: 'pending' | 'accepted' | 'expired';
}

/**
 * null means self-registered, not "sent by nobody"; `{ deleted: true }` means the
 * sender's account has since been deleted (User::delete() anonymizes in place rather
 * than removing the row, so naively rendering it would otherwise show a fake
 * `deleted-<id>@deleted.invalid` address as if it were real) — see InviteRecord's own
 * docblock. Takes already-translated strings rather than a translation key, since the
 * two call sites use different i18n namespaces (`adminInvites.*` vs
 * `platformAdmin.invites*`).
 */
export function inviteSenderLabel(
  invitedBy: Invite['invitedBy'],
  labels: { selfRegistered: string; senderDeleted: string },
): string {
  if (null === invitedBy) return labels.selfRegistered;
  if ('deleted' in invitedBy) return labels.senderDeleted;

  return invitedBy.name || invitedBy.email;
}

/** Same "accent = needs attention, accent-2 = healthy, neutral = still live" tag-color language AnketaList.svelte's own overdue badge already established. */
export function inviteStatusTagClass(status: Invite['status']): string {
  if ('accepted' === status) return 'tag-accent-2';
  if ('expired' === status) return 'tag-accent';

  return 'tag-neutral';
}
