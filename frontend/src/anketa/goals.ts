/**
 * "Цели": strategic goals spanning several review cycles, carried forward
 * (Phase 6c plan) into each new anketa for the pair while still
 * `in_progress`. Unlike everything else in the app, a goal's title/
 * description/targetDate/status are plaintext, server-managed, and
 * server-enforced (only its author may edit it) — see Goal.php on the
 * backend. `Goal` here mirrors that API resource; it's not a client-side
 * blob record, so unlike OutcomeItem there's no pure add/update function —
 * mutations are just PUT/POST calls, made directly in Anketa.svelte.
 */
type GoalStatus = 'in_progress' | 'achieved' | 'cancelled';

export interface Goal {
  id: string;
  goalUuid: string;
  authorId: string;
  title: string;
  description: string | null;
  targetDate: string | null;
  status: GoalStatus;
  createdAt: string;
}

/**
 * Progress checkpoints, by contrast, stay fully encrypted (goalCheckpointsBlob)
 * and follow the exact shared-blob pattern as comments/outcomes — same
 * reapply-on-conflict handling in Anketa.svelte, same client-only ownership
 * convention (normally only a goal's author adds checkpoints to it; the
 * counterpart comments instead, via comments.ts).
 */
export type CheckpointStatusTag = 'on_track' | 'at_risk' | 'blocked';

export interface GoalCheckpoint {
  id: string;
  /** A Goal's stable `goalUuid`, not its per-anketa row `id` — see Anketa.svelte's handleAddCheckpoint for why. */
  goalId: string;
  authorId: string;
  text?: string;
  statusTag?: CheckpointStatusTag;
  createdAt: string;
}

export function addCheckpoint(
  existing: GoalCheckpoint[],
  goalId: string,
  authorId: string,
  text?: string,
  statusTag?: CheckpointStatusTag,
): GoalCheckpoint[] {
  const checkpoint: GoalCheckpoint = {
    id: crypto.randomUUID(),
    goalId,
    authorId,
    ...(text ? { text } : {}),
    ...(statusTag ? { statusTag } : {}),
    createdAt: new Date().toISOString(),
  };
  return [...existing, checkpoint];
}
