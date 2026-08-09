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
      : users.filter((u) => u.email.toLowerCase().includes(query.trim().toLowerCase())),
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
    <ul class="results" id="user-typeahead-results">
      {#if filtered.length === 0}
        <li class="empty">{noResultsText}</li>
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

  input {
    width: 100%;
    box-sizing: border-box;
  }

  .results {
    position: absolute;
    z-index: 1;
    top: 100%;
    left: 0;
    right: 0;
    margin: 0.25rem 0 0;
    padding: 0.25rem;
    list-style: none;
    max-height: 12rem;
    overflow-y: auto;
    background: white;
    border: 1px solid #ddd;
    border-radius: 0.25rem;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
  }

  .results li {
    display: block;
  }

  .results button {
    display: block;
    width: 100%;
    box-sizing: border-box;
    padding: 0.4rem 0.5rem;
    text-align: left;
    background: none;
    border: none;
    border-radius: 0.2rem;
    cursor: pointer;
    font: inherit;
  }

  .results button.highlighted,
  .results button:hover {
    background: #f0f0f0;
  }

  .results .empty {
    padding: 0.4rem 0.5rem;
    color: #6b6b6b;
  }
</style>
