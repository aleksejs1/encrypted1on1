<script lang="ts">
  import { _ } from 'svelte-i18n';
  import DateInput from '../design/DateInput.svelte';
  import { ARCHIVE_HEADING_ID } from './archiveHeading';
  import type { CompanyTemplate } from '../api/types';
  import { ANKETA_TEMPLATES, templatePickerKeys } from './questions';
  import {
    customChoice,
    customTemplateIdOf,
    type TemplateChoice,
  } from './templateChoice';

  let {
    archiving,
    answersEditOpen,
    oneOff,
    skipNextMeeting = $bindable<boolean>(),
    nextMeetingDate = $bindable<string>(),
    nextTemplateChoice = $bindable<TemplateChoice | null>(),
    defaultChoice,
    companyTemplates,
    templateRetired,
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
    nextTemplateChoice: TemplateChoice | null;
    /** What the server does if nothing else is picked. */
    defaultChoice: TemplateChoice | null;
    /** The company's active templates (GitHub issue #144); empty if they couldn't be loaded. */
    companyTemplates: CompanyTemplate[];
    /**
     * This anketa's company template was archived, so the default is back to
     * Regular (#133 §7.4).
     */
    templateRetired: boolean;
    onArchive: (missed: boolean) => Promise<void>;
  } = $props();

  // The default must always be one of the options, even a company template
  // missing from the list (it failed to load, or changed meanwhile): otherwise
  // the select would show another option, and that would count as a change.
  const defaultMissing = $derived(
    defaultChoice !== null &&
      customTemplateIdOf(defaultChoice) !== null &&
      !companyTemplates.some(
        (template) => customChoice(template.id) === defaultChoice,
      ),
  );
</script>

<section class="card">
  <!-- tabindex="-1": focusable from script only, where the header's "not
       closed" card sends focus (GitHub issue #201). -->
  <h2 id={ARCHIVE_HEADING_ID} tabindex="-1">{$_('anketa.archiveHeading')}</h2>
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
          {#if defaultMissing}
            <option value={defaultChoice}
              >{$_('anketa.nextMeetingTypeSameTemplate')}</option
            >
          {/if}
          {#if companyTemplates.length > 0}
            <optgroup label={$_('anketa.nextMeetingTypeCompanyTemplates')}>
              {#each companyTemplates as template (template.id)}
                <option value={customChoice(template.id)}
                  >{template.name}</option
                >
              {/each}
            </optgroup>
          {/if}
        </select>
      </div>
      {#if templateRetired}
        <p class="text-muted archive-template-retired">
          {$_('anketa.templateRetired')}
        </p>
      {/if}
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

  .archive-template-retired {
    margin-bottom: 12px;
  }
</style>
