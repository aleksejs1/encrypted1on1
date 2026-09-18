<script lang="ts">
  import { _ } from 'svelte-i18n';
  import DateInput from '../design/DateInput.svelte';

  let {
    archiving,
    skipNextMeeting = $bindable<boolean>(),
    nextMeetingDate = $bindable<string>(),
    onArchive,
  }: {
    archiving: boolean;
    skipNextMeeting: boolean;
    nextMeetingDate: string;
    onArchive: (missed: boolean) => Promise<void>;
  } = $props();
</script>

<section class="card">
  <h2>{$_('anketa.archiveHeading')}</h2>
  <label class="radio archive-skip">
    <input
      type="checkbox"
      class="native-checkbox"
      bind:checked={skipNextMeeting}
    />
    {$_('anketa.skipNextMeeting')}
  </label>
  {#if !skipNextMeeting}
    <div class="field archive-date-field">
      <label for="next-meeting-date">{$_('anketa.nextMeetingDateLabel')}</label>
      <DateInput id="next-meeting-date" bind:value={nextMeetingDate} />
    </div>
  {/if}
  <button
    type="button"
    class="btn btn-primary"
    onclick={() => onArchive(false)}
    disabled={archiving}
  >
    {archiving ? $_('anketa.archiving') : $_('anketa.archive')}
  </button>
</section>

<style>
  .archive-skip {
    margin-bottom: 10px;
  }

  .native-checkbox {
    position: static;
    opacity: 1;
    width: auto;
    height: auto;
  }

  .archive-date-field {
    max-width: 220px;
    margin-bottom: 12px;
  }
</style>
