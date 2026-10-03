<script lang="ts">
  import { onMount } from 'svelte';
  import { _ } from 'svelte-i18n';
  import { apiGet, ApiError } from '../api/client';
  import { abortOnDestroy, isAbortError } from '../api/abortOnDestroy';
  import type { AnketaSummary } from '../api/types';
  import { startCreateWith } from '../anketa/createDefaults';
  import { pairCounterpartId, pairMeeting } from '../anketa/pairChain';
  import { ensureUnlocked } from '../crypto/identity.svelte';
  import { formatDisplayDate } from '../datePreference.svelte';
  import { navigate } from '../router.svelte';
  import { fullDisplayName } from '../userDisplay';

  /**
   * A pair's permanent link (GitHub issue #203), meant for a recurring
   * calendar event and the same for both people: it opens the pair's open
   * meeting, whichever that is by now. With none open it shows this page
   * instead: the last closed meeting and an offer to schedule the next one.
   */
  const { userIds }: { userIds: [string, string] } = $props();

  // Set once loaded; null for a link to a pair I'm not part of.
  let counterpartId: string | null = null;

  // Null while loading, and while the redirect to an open meeting is under way.
  let lastMeeting = $state<AnketaSummary | 'none' | null>(null);
  let loadError = $state<string | null>(null);

  const readAbort = abortOnDestroy();

  onMount(() => {
    Promise.all([
      ensureUnlocked(),
      apiGet<AnketaSummary[]>('/api/anketas', { signal: readAbort }),
    ])
      .then(([identity, anketas]) => {
        // The unlock isn't cancelled by leaving the page: no redirect from a
        // page that's gone.
        if (readAbort.aborted) return;
        counterpartId = pairCounterpartId(userIds, identity.userId);
        if (counterpartId === null) {
          lastMeeting = 'none';
          return;
        }
        const found = pairMeeting(anketas, counterpartId);
        if (found.kind === 'open') {
          // Replaces this entry, so Back doesn't land on the link and bounce
          // straight forward again.
          navigate(`/anketas/${found.anketa.id}`, { replace: true });
          return;
        }
        lastMeeting = found.kind === 'closed' ? found.anketa : 'none';
      })
      .catch((error: unknown) => {
        if (isAbortError(error)) return;
        loadError =
          error instanceof ApiError
            ? error.message
            : $_('anketaList.errorLoad');
      });
  });

  function scheduleNext(): void {
    if (counterpartId === null) return;
    startCreateWith(counterpartId);
    navigate('/anketas/new');
  }
</script>

<main>
  {#if loadError}
    <p class="banner-error" role="alert">{loadError}</p>
  {:else if lastMeeting === null}
    <p class="text-muted">{$_('common.loading')}</p>
  {:else if lastMeeting === 'none'}
    <div class="card elev-md">
      <h1>{$_('pairMeeting.noneTitle')}</h1>
      <p class="text-muted">{$_('pairMeeting.noneMessage')}</p>
      <a href="/" class="btn btn-primary">{$_('pairMeeting.backToList')}</a>
    </div>
  {:else}
    <div class="card elev-md">
      <h1>
        {$_('pairMeeting.closedTitle', {
          values: {
            name: lastMeeting.counterpartDeleted
              ? $_('anketaList.deletedCounterpart')
              : fullDisplayName(
                  lastMeeting.counterpartName,
                  lastMeeting.counterpartEmail,
                ),
          },
        })}
      </h1>
      <p class="text-muted">
        {$_('pairMeeting.closedMessage', {
          values: { date: formatDisplayDate(lastMeeting.meetingDate) },
        })}
      </p>
      <!-- A deleted colleague has no next meeting to schedule. -->
      {#if !lastMeeting.counterpartDeleted}
        <button type="button" class="btn btn-primary" onclick={scheduleNext}
          >{$_('pairMeeting.scheduleNext')}</button
        >
      {/if}
      <a href="/anketas/{lastMeeting.id}" class="btn btn-secondary"
        >{$_('pairMeeting.openLast')}</a
      >
    </div>
  {/if}
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
    width: min(440px, 100%);
    padding: 28px;
    gap: 12px;
    text-align: center;
  }

  h1 {
    font-size: 22px;
    margin: 0;
    overflow-wrap: anywhere;
  }

  p {
    font-size: 13px;
    margin: 0;
  }
</style>
