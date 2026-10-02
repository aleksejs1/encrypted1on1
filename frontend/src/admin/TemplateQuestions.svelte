<script lang="ts">
  import { _ } from 'svelte-i18n';
  import AnswerField from '../anketa/AnswerField.svelte';
  import {
    CURRENT_ANKETA_FORM_VERSION,
    displayText,
    questionsFromDefinition,
    SIDES,
    type AnswerValue,
  } from '../anketa/questions';
  import type { TemplateDefinition } from '../anketa/templateDefinition';

  /**
   * A template's questions as both sides will see them, with answer fields
   * to try out that save nothing: the editor's Preview and the share link's
   * preview page (GitHub issue #163). `definition` must be valid and trimmed.
   * `answers` is bindable so the editor can drop an answer whose field
   * changed type.
   */
  let {
    definition,
    headingLevel,
    answers = $bindable({}),
  }: {
    definition: TemplateDefinition;
    /** The level of each side's heading; a question's is one below. */
    headingLevel: 2 | 3;
    answers?: Record<string, AnswerValue>;
  } = $props();

  const sides = $derived(
    SIDES.map((side) => ({
      side,
      questions: questionsFromDefinition(
        definition,
        side,
        CURRENT_ANKETA_FORM_VERSION,
      ),
    })),
  );
</script>

{#each sides as { side, questions } (side)}
  <svelte:element this={`h${headingLevel}`} class="side-heading">
    {$_(`adminTemplateEditor.side.${side}`)}
  </svelte:element>
  {#each questions as question (question.id)}
    <div class="question">
      <svelte:element this={`h${headingLevel + 1}`}>
        {displayText(question, $_)}
      </svelte:element>
      <!-- Keyed on the type too: a new type needs a fresh field, not one
           holding the old type's answer. -->
      {#each question.fields as field (`${field.id}:${field.type}`)}
        <AnswerField {field} bind:value={answers[field.id]} />
      {/each}
    </div>
  {/each}
{/each}

<style>
  .side-heading {
    margin-top: 16px;
  }

  .question {
    margin: 12px 0;
  }
</style>
