<script lang="ts">
  type HealthStatus = { status: string };

  async function checkHealth(): Promise<HealthStatus> {
    const response = await fetch('/health');
    if (!response.ok) {
      throw new Error(`Backend returned ${response.status}`);
    }
    return response.json();
  }

  const health = checkHealth();
</script>

<main>
  <h1>encrypted1on1</h1>
  <p>Backend health check:</p>
  {#await health}
    <p>Checking…</p>
  {:then result}
    <pre>{JSON.stringify(result)}</pre>
  {:catch error}
    <p class="error">Could not reach the backend: {error.message}</p>
  {/await}
</main>

<style>
  main {
    max-width: 32rem;
    margin: 4rem auto;
    padding: 0 1rem;
    font-family: system-ui, sans-serif;
  }

  .error {
    color: #c0392b;
  }
</style>
