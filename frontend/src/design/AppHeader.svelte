<script lang="ts">
  import Logo from './Logo.svelte';
  import ThemeToggle from './ThemeToggle.svelte';
  import LanguageSwitcher from '../i18n/LanguageSwitcher.svelte';
</script>

<header class="app-header">
  <div class="brand">
    <Logo size={26} />
    <span class="wordmark">encrypted1on1</span>
  </div>
  <LanguageSwitcher />
  <ThemeToggle />
</header>

<style>
  .app-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px 20px;
    flex-wrap: wrap;
  }

  .brand {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-right: auto;
  }

  .wordmark {
    font-family: var(--font-heading);
    font-weight: var(--font-heading-weight);
    font-size: 17px;
  }
</style>
