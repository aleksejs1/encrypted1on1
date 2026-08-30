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

/**
 * An explicit `?lang=` in the URL wins over everything else, including a
 * previously stored preference — a link (e.g. the landing page's demo CTAs,
 * see e1o1-landing) should land in the language it promised, not whatever
 * this browser last had set. Persisted the same way setLocale() does, so a
 * later reload/navigation without the query param keeps the same choice.
 */
function detectUrlLocale(): SupportedLocale | null {
  const param = new URLSearchParams(window.location.search).get('lang');
  return isSupported(param) ? param : null;
}

function detectInitialLocale(): SupportedLocale {
  const fromUrl = detectUrlLocale();
  if (fromUrl) {
    localStorage.setItem(STORAGE_KEY, fromUrl);
    return fromUrl;
  }

  const stored = localStorage.getItem(STORAGE_KEY);
  if (isSupported(stored)) return stored;

  const browserLocale = getLocaleFromNavigator()?.split('-')[0];
  if (isSupported(browserLocale)) return browserLocale;

  return 'en';
}

// init()/locale.set() below are typed void | Promise<void> (svelte-i18n supports lazy
// locale loaders that return a promise) but never actually async here — every locale's
// messages are already loaded eagerly via addMessages() above, not svelte-i18n's lazy
// register(). Explicitly voided rather than awaited, matching that eager-load design.
void init({
  fallbackLocale: 'en',
  initialLocale: detectInitialLocale(),
});

/** Persists the choice (localStorage — a pure client-side UI preference, see the Phase 6h plan) alongside switching the active locale. */
export function setLocale(code: SupportedLocale): void {
  localStorage.setItem(STORAGE_KEY, code);
  void locale.set(code); // see the eager-load note on init() above — never actually async here
}
