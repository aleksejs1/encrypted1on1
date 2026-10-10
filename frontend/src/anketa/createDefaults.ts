/**
 * What the create form is handed from another page: the settings of
 * "Create another" (GitHub issue #198) and a pair link's colleague (#203).
 */
import { getGeneration } from '../crypto/identity.svelte';

/**
 * Whether a role chosen on the create form still stands once `counterpartId`
 * is chosen as the colleague (GitHub issue #251). A role is a statement
 * about one pair: it's dropped when it was chosen with another colleague
 * selected, and kept when it was chosen before any colleague
 * (`chosenWith` is '') or the same colleague is chosen again.
 *
 * The form never preselects a role. It used to, from the localStorage key
 * `e1o1:lastRole`, which is still in users' browsers: don't reuse the name.
 */
export function roleStandsFor(
  chosenWith: string,
  counterpartId: string,
): boolean {
  return chosenWith === '' || chosenWith === counterpartId;
}
import type { TemplateChoice } from './templateChoice';

/**
 * What "Create another" keeps. Colleague and date start empty again, and so
 * does the role, which is chosen for each colleague (GitHub issue #251).
 */
export interface CreateSettings {
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
