import { addMessages, getLocaleFromNavigator, init, locale } from 'svelte-i18n';
import en from './locales/en.json';
import ru from './locales/ru.json';
import lv from './locales/lv.json';
import es from './locales/es.json';

/**
 * The 4 launch-required locales (spec: "английский (дефолт), русский,
 * латышский, испанский — обязательны на старте"). Messages are added
 * directly (not svelte-i18n's lazy `register()`) — these files are small
 * enough that bundling all 4 together and initializing synchronously is
 * simpler than threading an async "locale still loading" state through
 * every page, for no real bundle-size benefit at this scale.
 */
export const SUPPORTED_LOCALES = ['en', 'ru', 'lv', 'es'] as const;
export type SupportedLocale = (typeof SUPPORTED_LOCALES)[number];

const STORAGE_KEY = 'e1o1:locale';

addMessages('en', en);
addMessages('ru', ru);
addMessages('lv', lv);
addMessages('es', es);

function isSupported(code: string | null | undefined): code is SupportedLocale {
  return (
    null != code && (SUPPORTED_LOCALES as readonly string[]).includes(code)
  );
}

function detectInitialLocale(): SupportedLocale {
  const stored = localStorage.getItem(STORAGE_KEY);
  if (isSupported(stored)) return stored;

  const browserLocale = getLocaleFromNavigator()?.split('-')[0];
  if (isSupported(browserLocale)) return browserLocale;

  return 'en';
}

init({
  fallbackLocale: 'en',
  initialLocale: detectInitialLocale(),
});

/** Persists the choice (localStorage — a pure client-side UI preference, see the Phase 6h plan) alongside switching the active locale. */
export function setLocale(code: SupportedLocale): void {
  localStorage.setItem(STORAGE_KEY, code);
  locale.set(code);
}
