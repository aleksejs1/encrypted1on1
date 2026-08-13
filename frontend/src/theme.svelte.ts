/**
 * Dark/light theme — same pattern as i18n/index.ts's locale detection
 * (localStorage override, else a system preference, applied immediately as
 * a module-level side effect) and auth.svelte.ts's $state-based reactive
 * state, just for theme instead of locale/auth.
 */

export type Theme = 'light' | 'dark';

const STORAGE_KEY = 'e1o1:theme';

function detectInitialTheme(): Theme {
  const stored = localStorage.getItem(STORAGE_KEY);
  if (stored === 'light' || stored === 'dark') return stored;

  return matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function apply(theme: Theme): void {
  document.documentElement.setAttribute('data-theme', theme);
}

export const themeState = $state<{ theme: Theme }>({
  theme: detectInitialTheme(),
});
apply(themeState.theme);

export function setTheme(theme: Theme): void {
  themeState.theme = theme;
  localStorage.setItem(STORAGE_KEY, theme);
  apply(theme);
}

export function toggleTheme(): void {
  setTheme(themeState.theme === 'dark' ? 'light' : 'dark');
}
