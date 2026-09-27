/**
 * A meeting-type choice as one string, the value of a picker's `<select>` or
 * radio group (GitHub issue #144, #133 §7.4): a built-in template key, or
 * `custom:<templateId>` for a company template. Split back into the request's
 * separate key and template-id fields only when it's sent.
 */
import type { AnketaTemplateKey, TemplateKey } from './questions';

export type TemplateChoice = TemplateKey | `custom:${string}`;

const CUSTOM_PREFIX = 'custom:';

/** The choice for company template `templateId`. */
export function customChoice(templateId: string): TemplateChoice {
  return `${CUSTOM_PREFIX}${templateId}`;
}

/** The company template a choice names, or null for a built-in one. */
export function customTemplateIdOf(choice: TemplateChoice): string | null {
  return choice.startsWith(CUSTOM_PREFIX)
    ? choice.slice(CUSTOM_PREFIX.length)
    : null;
}

/** A create request's template fields for `choice`. */
export function templateFields(choice: TemplateChoice): {
  templateKey: AnketaTemplateKey;
  customTemplateId?: string;
} {
  const templateId = customTemplateIdOf(choice);
  return templateId === null
    ? { templateKey: choice as TemplateKey }
    : { templateKey: 'custom', customTemplateId: templateId };
}

/**
 * The archive form's default choice, from the detail's next-template fields
 * (`AnketaLifecycleService::defaultNextTemplate()`). Null when there's no
 * successor to choose for.
 */
export function defaultNextChoice(detail: {
  nextCycleTemplateKey: AnketaTemplateKey | null;
  nextCustomTemplateId: string | null;
}): TemplateChoice | null {
  const key = detail.nextCycleTemplateKey;
  if (key !== 'custom') return key;
  return detail.nextCustomTemplateId === null
    ? null
    : customChoice(detail.nextCustomTemplateId);
}

/**
 * An archive request's template fields: nothing while `choice` is still the
 * default, so an untouched form sends exactly the old request and the server
 * applies its own current default (#133 §7.1). That's also what keeps
 * archiving working when an admin archived the default template meanwhile.
 */
export function nextTemplateFields(
  choice: TemplateChoice | null,
  defaultChoice: TemplateChoice | null,
): { nextTemplateKey?: AnketaTemplateKey; nextCustomTemplateId?: string } {
  if (choice === null || choice === defaultChoice) return {};
  const { templateKey, customTemplateId } = templateFields(choice);
  return customTemplateId === undefined
    ? { nextTemplateKey: templateKey }
    : { nextTemplateKey: templateKey, nextCustomTemplateId: customTemplateId };
}
