<script lang="ts">
  import { locale, _ } from 'svelte-i18n';
  import { SUPPORTED_LOCALES, setLocale, type SupportedLocale } from './index';

  const LOCALE_NAMES: Record<SupportedLocale, string> = {
    en: 'English',
    ru: 'Русский',
    lv: 'Latviešu',
    es: 'Español',
  };
</script>

<label class="language-switcher">
  <span class="sr-only">{$_('languageSwitcher.label')}</span>
  <select value={$locale} onchange={(e) => setLocale(e.currentTarget.value as SupportedLocale)}>
    {#each SUPPORTED_LOCALES as code (code)}
      <option value={code}>{LOCALE_NAMES[code]}</option>
    {/each}
  </select>
</label>

<style>
  .language-switcher {
    display: flex;
    justify-content: flex-end;
    padding: 0.5rem 1rem 0;
    max-width: 48rem;
    margin: 0 auto;
    font-family: system-ui, sans-serif;
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
    font: inherit;
  }
</style>
