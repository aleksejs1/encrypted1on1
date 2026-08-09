<script lang="ts">
  import { locale, _ } from 'svelte-i18n';
  import { SUPPORTED_LOCALES, setLocale, type SupportedLocale } from './index';
  import { apiPut } from '../api/client';

  const LOCALE_NAMES: Record<SupportedLocale, string> = {
    en: 'English',
    ru: 'Русский',
    lv: 'Latviešu',
    es: 'Español',
  };

  function handleChange(code: SupportedLocale): void {
    setLocale(code);
    // Best-effort background sync of which language *emails* go out in (Phase 6i) — silently
    // ignored when not authenticated (Login/Activate pages) and never blocks the UI switch,
    // which is what setLocale() above already did, synchronously and unconditionally.
    apiPut('/api/me/locale', { locale: code }).catch(() => {});
  }
</script>

<label class="language-switcher">
  <span class="sr-only">{$_('languageSwitcher.label')}</span>
  <select
    class="input"
    value={$locale}
    onchange={(e) => handleChange(e.currentTarget.value as SupportedLocale)}
  >
    {#each SUPPORTED_LOCALES as code (code)}
      <option value={code}>{LOCALE_NAMES[code]}</option>
    {/each}
  </select>
</label>

<style>
  .language-switcher {
    display: inline-flex;
  }

  .sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
  }

  select {
    width: auto;
    min-height: 32px;
    padding: 4px 12px;
    font-size: 13px;
  }
</style>
