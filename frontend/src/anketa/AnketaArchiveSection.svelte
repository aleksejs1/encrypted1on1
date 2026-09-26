<script lang="ts">
  import { _ } from 'svelte-i18n';
  import DateInput from '../design/DateInput.svelte';
  import {
    ANKETA_TEMPLATES,
    templatePickerKeys,
    type TemplateKey,
  } from './questions';

  let {
    archiving,
    answersEditOpen,
    oneOff,
    skipNextMeeting = $bindable<boolean>(),
    nextMeetingDate = $bindable<string>(),
    nextTemplateChoice = $bindable<TemplateKey | null>(),
    onArchive,
  }: {
    archiving: boolean;
    /**
     * An unsaved edit of my own published answers is open. Archiving now would
     * make it unsaveable (editing is never offered on an archived anketa), so
     * the button waits until it's saved or cancelled.
     */
    answersEditOpen: boolean;
    oneOff: boolean;
    skipNextMeeting: boolean;
    nextMeetingDate: string;
    nextTemplateChoice: TemplateKey | null;
    onArchive: (missed: boolean) => Promise<void>;
  } = $props();
</script>

<section class="card">
  <h2>{$_('anketa.archiveHeading')}</h2>
  {#if oneOff}
    <p class="text-muted archive-no-next">
      {$_('anketa.archiveOneOff')}
    </p>
  {:else}
    <label class="radio archive-skip">
      <input
        type="checkbox"
        class="native-checkbox"
        bind:checked={skipNextMeeting}
        disabled={archiving}
      />
      {$_('anketa.skipNextMeeting')}
    </label>
    {#if !skipNextMeeting}
      <div class="field archive-date-field">
        <label for="next-meeting-date"
          >{$_('anketa.nextMeetingDateLabel')}</label
        >
        <DateInput
          id="next-meeting-date"
          bind:value={nextMeetingDate}
          disabled={archiving}
        />
      </div>
      <div class="field archive-template-field">
        <label for="next-meeting-type"
          >{$_('anketa.nextMeetingTypeLabel')}</label
        >
        <select
          id="next-meeting-type"
          class="input"
          bind:value={nextTemplateChoice}
          disabled={archiving}
        >
          {#each ANKETA_TEMPLATES as key (key)}
            <option value={key}>{$_(templatePickerKeys(key).labelKey)}</option>
          {/each}
        </select>
      </div>
    {/if}
  {/if}
  {#if answersEditOpen}
    <p id="archive-after-edit-hint" class="text-muted">
      {$_('anketa.archiveAfterAnswersEdit')}
    </p>
  {/if}
  <button
    type="button"
    class="btn btn-primary"
    onclick={() => onArchive(false)}
    disabled={archiving || answersEditOpen}
    aria-describedby={answersEditOpen ? 'archive-after-edit-hint' : undefined}
  >
    {archiving ? $_('anketa.archiving') : $_('anketa.archive')}
  </button>
</section>

<style>
  .archive-skip,
  .archive-no-next {
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

  .archive-template-field {
    max-width: 320px;
    margin-bottom: 12px;
  }
</style>
