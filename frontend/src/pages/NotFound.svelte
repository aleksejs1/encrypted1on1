<script lang="ts">
  import { _ } from 'svelte-i18n';
</script>

<main>
  <div class="card elev-md">
    <h1>{$_('notFound.title')}</h1>
    <p class="text-muted subtitle">{$_('notFound.message')}</p>
    <a href="/" class="btn btn-primary btn-block">{$_('notFound.backHome')}</a>
  </div>
</main>

<style>
  main {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
  }

  .card {
    width: min(400px, 100%);
    padding: 28px;
    text-align: center;
  }

  h1 {
    font-size: 26px;
    margin: 0 0 4px;
  }

  .subtitle {
    font-size: 13px;
    margin: 0 0 20px;
  }
</style>
