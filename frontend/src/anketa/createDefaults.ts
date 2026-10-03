/**
 * The create form's role default and its "Create another" hand-over
 * (GitHub issue #198).
 */
import type { AnketaSummary } from '../api/types';
import { getGeneration } from '../crypto/identity.svelte';
import type { Side } from './questions';
import type { TemplateChoice } from './templateChoice';

const STORAGE_KEY = 'e1o1:lastRole';

function isSide(value: unknown): value is Side {
  return value === 'employee' || value === 'manager';
}

/**
 * My role in the most recent meeting with `counterpartId`, or null for a new
 * pair. One-offs count too: the role is the same person's either way. Latest
 * by meetingDate, then id (UUIDv7 ids sort by creation time).
 */
export function pairRole(
  priorAnketas: Pick<
    AnketaSummary,
    'id' | 'counterpartId' | 'meetingDate' | 'myRole'
  >[],
  counterpartId: string,
): Side | null {
  let latest: (typeof priorAnketas)[number] | null = null;
  for (const anketa of priorAnketas) {
    if (anketa.counterpartId !== counterpartId) continue;
    const byDate =
      latest === null
        ? 1
        : Date.parse(anketa.meetingDate) - Date.parse(latest.meetingDate);
    if (
      byDate > 0 ||
      (byDate === 0 && latest !== null && anketa.id > latest.id)
    )
      latest = anketa;
  }
  return latest?.myRole ?? null;
}

/**
 * The role to preselect: the pair's history, else the user's last choice,
 * else null — nothing preselected, so the user has to pick one.
 */
export function defaultRole(
  fromPair: Side | null,
  lastChosen: Side | null,
): Side | null {
  return fromPair ?? lastChosen;
}

/**
 * The role last created with on this device (localStorage, like theme and
 * language). Storage that throws (blocked, full) counts as nothing stored:
 * the create form calls these around a request that must not look failed
 * once the server has made the 1:1.
 */
export function readLastRole(): Side | null {
  try {
    const stored = localStorage.getItem(STORAGE_KEY);
    return isSide(stored) ? stored : null;
  } catch {
    return null;
  }
}

export function rememberLastRole(role: Side): void {
  try {
    localStorage.setItem(STORAGE_KEY, role);
  } catch {
    // Not remembered; the next form just asks again.
  }
}

/** What "Create another" keeps: colleague and date start empty again. */
export interface CreateSettings {
  role: Side;
  templateChoice: TemplateChoice;
  periodicityDays: number;
}

/**
 * The meeting this tab just created and the settings it was created with, so
 * its page can offer "Create another", and the settings on their way back to
 * the form once that's clicked. In memory only, like templatePortability.ts's
 * pending import: a reload drops both, and leaving the meeting's page drops
 * the offer (clearJustCreated()). Tied to the login generation, so one
 * user's settings can't turn up for the next one to log in on this tab.
 */
let justCreated: {
  anketaId: string;
  settings: CreateSettings;
  generation: number;
} | null = null;
let pending: { settings: CreateSettings; generation: number } | null = null;

export function setJustCreated(
  anketaId: string,
  settings: CreateSettings,
): void {
  justCreated = { anketaId, settings, generation: getGeneration() };
}

/** The created meeting's page was left: coming back to it later offers nothing. */
export function clearJustCreated(): void {
  justCreated = null;
}

/** Whether `anketaId` is the meeting this tab just created. */
export function isJustCreated(anketaId: string): boolean {
  return (
    justCreated !== null &&
    justCreated.anketaId === anketaId &&
    justCreated.generation === getGeneration()
  );
}

/** "Create another" was clicked on `anketaId`'s page: hands its settings to the form. */
export function startCreateAnother(anketaId: string): void {
  if (!isJustCreated(anketaId) || justCreated === null) return;
  pending = {
    settings: justCreated.settings,
    generation: justCreated.generation,
  };
}

/** The settings handed over by startCreateAnother(), once: taking them clears them. */
export function takeCreateAnother(): CreateSettings | null {
  const taken = pending;
  pending = null;
  return taken !== null && taken.generation === getGeneration()
    ? taken.settings
    : null;
}

/**
 * The colleague to preselect on the form, set by a pair's permanent link when
 * its last meeting is closed (GitHub issue #203). In memory and tied to the
 * login generation, like `pending` above.
 */
let pendingCounterpart: { counterpartId: string; generation: number } | null =
  null;

/** "Schedule the next one" was clicked for `counterpartId`: hands them to the form. */
export function startCreateWith(counterpartId: string): void {
  pendingCounterpart = { counterpartId, generation: getGeneration() };
}

/** The colleague handed over by startCreateWith(), once: taking them clears them. */
export function takeCreateWith(): string | null {
  const taken = pendingCounterpart;
  pendingCounterpart = null;
  return taken !== null && taken.generation === getGeneration()
    ? taken.counterpartId
    : null;
}
