/**
 * Hand-rolled — no router library. A handful of path shapes doesn't change
 * that calculus (see the Phase 4/5 plans); this just avoids full page
 * reloads on navigation, which would otherwise throw away the in-memory
 * unwrapped private key (identity.ts) for no reason.
 */
export const routerState = $state({ path: window.location.pathname });

window.addEventListener('popstate', () => {
  routerState.path = window.location.pathname;
});

export function navigate(to: string): void {
  history.pushState(null, '', to);
  routerState.path = to;
}
