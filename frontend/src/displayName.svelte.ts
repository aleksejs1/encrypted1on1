/**
 * Reactive mirror of the logged-in user's display name — separate from
 * identity.ts's plain (non-reactive) Identity cache because AppHeader.svelte stays
 * mounted for the whole authenticated session (see App.svelte), so it needs to pick
 * up a name change AccountSettings.svelte saves without a full page reload. Same
 * cross-component-update need auth.svelte.ts's authState solves for login state.
 */
export const displayNameState = $state<{ value: string }>({ value: '' });

export function setDisplayNameState(value: string): void {
  displayNameState.value = value;
}
