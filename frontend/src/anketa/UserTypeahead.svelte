<script lang="ts">
  import { onDestroy } from 'svelte';
  import { nameWithEmail } from '../userDisplay';

  interface UserOption {
    id: string;
    email: string;
    displayName: string;
  }

  let {
    users,
    value = $bindable(''),
    placeholder,
    noResultsText,
    badgeFor,
  }: {
    users: UserOption[];
    value?: string;
    placeholder: string;
    noResultsText: string;
    /** A short fact shown beside a person in the list, e.g. "Your manager". */
    badgeFor?: (userId: string) => string | null;
  } = $props();

  let query = $state('');
  let open = $state(false);
  let highlightedIndex = $state(0);
  // Closing waits a moment after blur, so a click on a suggestion lands
  // first. Focusing the field again within that moment keeps the list open:
  // without the cancel, the old timer closed the list just reopened.
  let closeTimer: ReturnType<typeof setTimeout> | undefined;

  onDestroy(() => clearTimeout(closeTimer));

  function openList(): void {
    clearTimeout(closeTimer);
    open = true;
  }

  const filtered = $derived(
    query.trim() === ''
      ? users
      : users.filter((u) => {
          const needle = query.trim().toLowerCase();
          return (
            u.email.toLowerCase().includes(needle) ||
            u.displayName.toLowerCase().includes(needle)
          );
        }),
  );

  // A value set from outside (a colleague preselected by the page) shows
  // that person, as picking them from the list would.
  $effect(() => {
    const selected = users.find((u) => u.id === value);
    if (selected) query = nameWithEmail(selected.displayName, selected.email);
  });

  function selectUser(user: UserOption): void {
    value = user.id;
    query = nameWithEmail(user.displayName, user.email);
    open = false;
  }

  function handleInput(): void {
    // Typing again invalidates any prior selection, so a stale id can never be
    // silently submitted once the visible text no longer matches it.
    if (value !== '') value = '';
    openList();
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
    onfocus={openList}
    onblur={() => (closeTimer = setTimeout(() => (open = false), 150))}
    onkeydown={handleKeydown}
    {placeholder}
  />
  {#if open}
    <ul class="results card elev-md" id="user-typeahead-results">
      {#if filtered.length === 0}
        <li class="empty text-muted">{noResultsText}</li>
      {:else}
        {#each filtered as user, i (user.id)}
          {@const badge = badgeFor?.(user.id) ?? null}
          <li>
            <button
              type="button"
              class:highlighted={i === highlightedIndex}
              onmousedown={() => selectUser(user)}
            >
              {nameWithEmail(user.displayName, user.email)}
              {#if badge !== null}
                <span class="tag tag-neutral badge">{badge}</span>
              {/if}
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

  .badge {
    margin-left: 6px;
  }

  .results .empty {
    padding: 8px 10px;
    font-size: 13px;
  }
</style>
