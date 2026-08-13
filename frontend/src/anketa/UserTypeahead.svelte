<script lang="ts">
  interface UserOption {
    id: string;
    email: string;
  }

  let {
    users,
    value = $bindable(''),
    placeholder,
    noResultsText,
  }: {
    users: UserOption[];
    value?: string;
    placeholder: string;
    noResultsText: string;
  } = $props();

  let query = $state('');
  let open = $state(false);
  let highlightedIndex = $state(0);

  const filtered = $derived(
    query.trim() === ''
      ? users
      : users.filter((u) =>
          u.email.toLowerCase().includes(query.trim().toLowerCase()),
        ),
  );

  function selectUser(user: UserOption): void {
    value = user.id;
    query = user.email;
    open = false;
  }

  function handleInput(): void {
    // Typing again invalidates any prior selection, so a stale id can never be
    // silently submitted once the visible text no longer matches it.
    if (value !== '') value = '';
    open = true;
    highlightedIndex = 0;
  }

  function handleKeydown(event: KeyboardEvent): void {
    if (!open) return;
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      highlightedIndex = Math.min(highlightedIndex + 1, filtered.length - 1);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      highlightedIndex = Math.max(highlightedIndex - 1, 0);
    } else if (event.key === 'Enter') {
      event.preventDefault();
      const user = filtered[highlightedIndex];
      if (user) selectUser(user);
    } else if (event.key === 'Escape') {
      open = false;
    }
  }
</script>

<div class="typeahead">
  <input
    class="input"
    type="text"
    role="combobox"
    aria-expanded={open}
    aria-controls="user-typeahead-results"
    autocomplete="off"
    bind:value={query}
    oninput={handleInput}
    onfocus={() => (open = true)}
    onblur={() => setTimeout(() => (open = false), 150)}
    onkeydown={handleKeydown}
    {placeholder}
  />
  {#if open}
    <ul class="results card elev-md" id="user-typeahead-results">
      {#if filtered.length === 0}
        <li class="empty text-muted">{noResultsText}</li>
      {:else}
        {#each filtered as user, i (user.id)}
          <li>
            <button
              type="button"
              class:highlighted={i === highlightedIndex}
              onmousedown={() => selectUser(user)}
            >
              {user.email}
            </button>
          </li>
        {/each}
      {/if}
    </ul>
  {/if}
</div>

<style>
  .typeahead {
    position: relative;
  }

  .results {
    position: absolute;
    z-index: 1;
    top: 100%;
    left: 0;
    right: 0;
    margin: 4px 0 0;
    padding: 6px;
    list-style: none;
    max-height: 12rem;
    overflow-y: auto;
    gap: 2px;
  }

  .results li {
    display: block;
  }

  .results button {
    display: block;
    width: 100%;
    box-sizing: border-box;
    padding: 8px 10px;
    text-align: left;
    background: none;
    border: none;
    border-radius: var(--radius-sm);
    cursor: pointer;
    font: inherit;
    font-size: 13px;
    color: inherit;
  }

  .results button.highlighted,
  .results button:hover {
    background: color-mix(in srgb, var(--color-text) 7%, transparent);
  }

  .results .empty {
    padding: 8px 10px;
    font-size: 13px;
  }
</style>
