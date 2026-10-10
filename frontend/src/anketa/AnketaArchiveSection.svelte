<script lang="ts">
  import { _ } from 'svelte-i18n';
  import DateInput from '../design/DateInput.svelte';
  import ArchiveConfirm from './ArchiveConfirm.svelte';
  import type { ArchiveConfirmation } from './archiveConfirmation';
  import { ARCHIVE_HEADING_ID } from './archiveHeading';
  import type { CompanyTemplate } from '../api/types';
  import { ANKETA_TEMPLATES, templatePickerKeys } from './questions';
  import {
    customChoice,
    customTemplateIdOf,
    type TemplateChoice,
  } from './templateChoice';

  let {
    anketaId,
    archiving,
    publishing,
    answersEditOpen,
    confirmation,
    counterpartName,
    oneOff,
    skipNextMeeting = $bindable<boolean>(),
    nextMeetingDate = $bindable<string>(),
    nextTemplateChoice = $bindable<TemplateChoice | null>(),
    swapRolesNext = $bindable<boolean>(),
    swapNote,
    defaultChoice,
    companyTemplates,
    templateRetired,
    onArchive,
  }: {
    anketaId: string;
    /** Closing the meeting is in flight: the archive, or the publish before it. */
    archiving: boolean;
    /** Any publish of my side is in flight; closing waits for it, since it may publish too. */
    publishing: boolean;
    /**
     * An unsaved edit of my own published answers is open. Archiving now would
     * make it unsaveable (editing is never offered on an archived anketa), so
     * the button waits until it's saved or cancelled. Also an open edit of a
     * list entry in a draft that closing would publish.
     */
    answersEditOpen: boolean;
    confirmation: ArchiveConfirmation;
    counterpartName: string;
    oneOff: boolean;
    skipNextMeeting: boolean;
    nextMeetingDate: string;
    nextTemplateChoice: TemplateChoice | null;
    /** Create the next 1:1 with the two roles swapped (GitHub issue #255). */
    swapRolesNext: boolean;
    /**
     * Who answers as which in the next 1:1, while the swap is ticked and
     * will apply; undefined otherwise.
     */
    swapNote: string | undefined;
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
        onchange={(event) => {
          // The swap checkbox goes out of sight with this ticked; a tick
          // nobody can see mustn't come back with it.
          if (event.currentTarget.checked) swapRolesNext = false;
        }}
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
      <div class="archive-swap-roles">
        <label class="radio">
          <input
            type="checkbox"
            class="native-checkbox"
            bind:checked={swapRolesNext}
            disabled={archiving}
          />
          {$_('anketa.swapRolesNext')}
        </label>
        <!-- The result, not who holds which role now. A live region, there
             whenever the checkbox is, not the checkbox's description: the
             text appears after the checkbox got focus, when a description
             is no longer read out. -->
        <p
          id="swap-roles-result"
          class="text-muted swap-roles-result"
          aria-live="polite"
        >
          {swapNote ?? ''}
        </p>
      </div>
    {/if}
  {/if}
  {#if answersEditOpen}
    <p id="archive-after-edit-hint" class="text-muted">
      {$_('anketa.archiveAfterAnswersEdit')}
    </p>
  {/if}
  <!-- Secondary: while my side is unpublished, Publish is the page's only
       primary button, so "the big button at the bottom" isn't this one
       (GitHub issue #229). -->
  <ArchiveConfirm
    {anketaId}
    textId="archive-confirm-text"
    {confirmation}
    {counterpartName}
    triggerClass="btn btn-secondary"
    triggerLabel={oneOff || skipNextMeeting
      ? $_('anketa.closeOneOff')
      : $_('anketa.closeAndScheduleNext')}
    closing={archiving}
    busy={archiving || publishing}
    confirmLabel={$_('anketa.closeOneOff')}
    busyLabel={$_('anketa.archiving')}
    blocked={answersEditOpen}
    describedBy="archive-after-edit-hint"
    {swapNote}
    onConfirm={() => onArchive(false)}
  />
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

  .archive-template-retired,
  .archive-swap-roles {
    margin-bottom: 12px;
  }

  .swap-roles-result {
    font-size: 12px;
    margin: 4px 0 0;
  }
</style>
