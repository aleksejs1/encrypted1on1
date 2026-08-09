<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { themeState, toggleTheme } from '../theme.svelte';
</script>

<button
  type="button"
  class="btn btn-secondary btn-icon"
  onclick={toggleTheme}
  aria-label={themeState.theme === 'dark' ? $_('theme.switchToLight') : $_('theme.switchToDark')}
>
  {themeState.theme === 'dark' ? '☀' : '☾'}
</button>
