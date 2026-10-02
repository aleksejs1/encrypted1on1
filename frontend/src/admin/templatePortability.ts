/**
 * Moving a company template between libraries (GitHub issue #163): a `.json`
 * export file, a share link carrying the template in its URL fragment, and
 * the importer both lead to. A template is configuration, not user content
 * (D1 in #133): only its name, description and questions travel, never an
 * answer. A fragment is never sent to the server, so a share link reveals
 * nothing to the instance it points at until someone installs the template.
 *
 * Importing never saves anything: the importer validates what it's given with
 * the same rules as the server, gives every custom id a fresh value, and hands
 * the result to the editor's new-template form (setPendingImport()), where the
 * admin reviews it and saves it like any other new template.
 */
import { PATHS } from '../routes';
import { getGeneration } from '../crypto/identity.svelte';
import {
  MAX_TEMPLATE_DESCRIPTION_LENGTH,
  MAX_TEMPLATE_NAME_LENGTH,
  templateTextProblem,
  trimTemplateDefinition,
  trimTemplateText,
  validateTemplateDefinition,
  has,
  isObject,
  type TemplateBlock,
  type TemplateDefinition,
  type TemplateDefinitionError,
} from '../anketa/templateDefinition';
import {
  errorLocation,
  errorMessageValues,
  newCustomId,
  newOptionValue,
  withoutBlankLabels,
} from './templateEditor';

/** What an export file or a share link carries. */
export interface PortableTemplate {
  name: string;
  description: string;
  definition: TemplateDefinition;
}

/** The export file's own format version, separate from the definition's. */
const EXPORT_SCHEMA_VERSION = 1;

export type ImportProblem =
  | { code: 'json' }
  | { code: 'link' }
  | { code: 'shape' }
  | { code: 'newer' }
  | { code: 'browser' }
  | { code: 'name' }
  | { code: 'description' }
  | { code: 'definition'; errors: TemplateDefinitionError[] };

export type ImportResult =
  | { ok: true; template: PortableTemplate }
  | { ok: false; problem: ImportProblem };

type Translate = (
  key: string,
  options?: { values?: Record<string, string | number> },
) => string;

/**
 * What to tell the admin about a refused import. A definition the server
 * would refuse is described by its first problem, in the editor's words.
 */
export function importProblemMessage(
  problem: ImportProblem,
  translate: Translate,
): string {
  if (problem.code !== 'definition') {
    return translate(`templatePortability.errors.${problem.code}`);
  }
  const [{ code, path }] = problem.errors;
  const reason = translate(`adminTemplateEditor.errors.${code}`, {
    values: errorMessageValues(code, path),
  });
  const location = errorLocation(path);
  const where =
    location === null
      ? null
      : location.number === null
        ? translate(`adminTemplateEditor.side.${location.side}`)
        : translate('adminTemplateEditor.location', {
            values: {
              side: translate(`adminTemplateEditor.side.${location.side}`),
              number: location.number,
            },
          });
  return translate('templatePortability.errors.definition', {
    values: { reason: where === null ? reason : `${reason} (${where})` },
  });
}

/**
 * The export file's content: the template inside an envelope saying what it
 * is, when it was exported and by which app version.
 */
export function exportFileContent(
  template: PortableTemplate,
  exportedAt: Date,
  appVersion: string,
): string {
  const envelope = {
    schemaVersion: EXPORT_SCHEMA_VERSION,
    exportedAt: exportedAt.toISOString(),
    appVersion,
    template: {
      name: template.name,
      description: template.description,
      definition: template.definition,
    },
  };
  return `${JSON.stringify(envelope, null, 2)}\n`;
}

/**
 * `encrypted1on1-template-<slug>.json`, the slug made of the name's ASCII
 * letters and digits; a name with none (Cyrillic, say) gives `template`.
 */
export function exportFileName(name: string): string {
  const slug = name
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 60)
    .replace(/-+$/, '');
  return `encrypted1on1-template-${slug || 'template'}.json`;
}

/**
 * Errors about values withFreshIds() replaces anyway. The validator reports
 * them without skipping anything else, so the rest of the definition is
 * still fully checked when they're ignored.
 */
const REPLACED_ID_CODES: ReadonlySet<TemplateDefinitionError['code']> = new Set(
  [
    'id_format',
    'id_duplicate',
    'option_value_format',
    'option_value_duplicate',
  ],
);

/**
 * The template in parsed JSON, which may be an export file's envelope, a bare
 * `{name, description, definition}` or a definition on its own (named
 * `defaultName`). Accepted only if the server would accept it: the name and
 * description by the same rules as the editor's, the definition by
 * validateTemplateDefinition(). Every text comes back trimmed, and every
 * custom id and option value fresh (withFreshIds()).
 */
export function importTemplate(
  value: unknown,
  defaultName: string,
): ImportResult {
  const fail = (problem: ImportProblem): ImportResult => ({
    ok: false,
    problem,
  });
  if (!isObject(value)) return fail({ code: 'shape' });

  let inner: unknown;
  if (has(value, 'template')) {
    const version = value.schemaVersion;
    if (typeof version === 'number' && version > EXPORT_SCHEMA_VERSION) {
      return fail({ code: 'newer' });
    }
    if (version !== EXPORT_SCHEMA_VERSION) return fail({ code: 'shape' });
    inner = value.template;
  } else if (has(value, 'definition')) {
    inner = value;
  } else if (has(value, 'employee') || has(value, 'manager')) {
    inner = { definition: value };
  } else {
    return fail({ code: 'shape' });
  }
  if (!isObject(inner) || !isObject(inner.definition)) {
    return fail({ code: 'shape' });
  }
  // A definition from a later app version is told apart from a damaged one,
  // for a share link (which has no envelope) as much as for a file.
  const definitionVersion = inner.definition.schemaVersion;
  if (typeof definitionVersion === 'number' && definitionVersion > 1) {
    return fail({ code: 'newer' });
  }

  const name = has(inner, 'name') ? inner.name : defaultName;
  const description = has(inner, 'description') ? inner.description : '';
  if (templateTextProblem(name, MAX_TEMPLATE_NAME_LENGTH) !== null) {
    return fail({ code: 'name' });
  }
  if (
    templateTextProblem(description, MAX_TEMPLATE_DESCRIPTION_LENGTH, true) !==
    null
  ) {
    return fail({ code: 'description' });
  }
  // A blank hint means none, as in the editor. Ids and option values are
  // replaced below, so only a problem with something else refuses the file;
  // what's left is then checked in full.
  const cleaned = withoutBlankLabels(
    inner.definition as unknown as TemplateDefinition,
  );
  const errors = validateTemplateDefinition(cleaned).filter(
    (error) => !REPLACED_ID_CODES.has(error.code),
  );
  if (errors.length > 0) return fail({ code: 'definition', errors });
  const definition = withFreshIds(trimTemplateDefinition(cleaned));
  // Load-bearing, not a safety net: the validator checks the size cap only
  // once nothing else is wrong, so with an id error ignored above, this pass
  // is the one that checks it.
  const remapped = validateTemplateDefinition(definition);
  if (remapped.length > 0) {
    return fail({ code: 'definition', errors: remapped });
  }
  return {
    ok: true,
    template: {
      name: trimTemplateText(name as string),
      description: trimTemplateText(description as string),
      definition,
    },
  };
}

/** Far more than any template the server accepts: a bigger file is refused unread. */
export const MAX_IMPORT_FILE_BYTES = 1024 * 1024;

/** importTemplate() for a file's text. */
export function importTemplateFile(
  text: string,
  defaultName: string,
): ImportResult {
  let value: unknown;
  try {
    value = JSON.parse(text);
  } catch {
    return { ok: false, problem: { code: 'json' } };
  }
  return importTemplate(value, defaultName);
}

/**
 * `definition` with a new id for every custom block and field, and a new value
 * for every choice; built-in blocks are kept as they are. Ids from outside
 * this library are never kept: a fresh set can't collide with anything here.
 * Each new id is checked against the ones already given out, so they stay
 * unique however the generator behaves.
 */
export function withFreshIds(
  definition: TemplateDefinition,
  newId: () => string = newCustomId,
  newValue: () => string = newOptionValue,
): TemplateDefinition {
  const unique = (generate: () => string, used: Set<string>): string => {
    let value = generate();
    while (used.has(value)) value = generate();
    used.add(value);
    return value;
  };
  const ids = new Set<string>();
  const remap = (block: TemplateBlock): TemplateBlock => {
    if (block.kind === 'builtin') return { ...block };
    const values = new Set<string>();
    const { options, ...field } = block.field;
    return {
      ...block,
      id: unique(newId, ids),
      field: {
        ...field,
        id: unique(newId, ids),
        ...(options === undefined
          ? {}
          : {
              options: options.map((option) => ({
                ...option,
                value: unique(newValue, values),
              })),
            }),
      },
    };
  };
  return {
    schemaVersion: definition.schemaVersion,
    employee: definition.employee.map(remap),
    manager: definition.manager.map(remap),
  };
}

/** A fragment's format prefix: deflate-raw compressed, or not. */
const COMPRESSED = 'v1z:';
const RAW = 'v1r:';
/** Past this, a link is long enough for some apps to cut it short. */
export const LONG_LINK_LENGTH = 2048;
/**
 * Nothing the server accepts comes near either cap. The fragment cap also
 * bounds an uncompressed link's payload (four characters to three bytes), so
 * only an inflated one needs checking against the payload cap.
 */
const MAX_FRAGMENT_LENGTH = 200_000;
const MAX_PAYLOAD_BYTES = 256 * 1024;

function toBase64Url(bytes: Uint8Array): string {
  let binary = '';
  for (let start = 0; start < bytes.length; start += 0x8000) {
    binary += String.fromCharCode(...bytes.subarray(start, start + 0x8000));
  }
  return btoa(binary)
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '');
}

function fromBase64Url(text: string): Uint8Array | null {
  if (!/^[A-Za-z0-9_-]*$/.test(text)) return null;
  const base64 = text.replace(/-/g, '+').replace(/_/g, '/');
  try {
    const binary = atob(base64.padEnd(Math.ceil(base64.length / 4) * 4, '='));
    return Uint8Array.from(binary, (char) => char.charCodeAt(0));
  } catch {
    return null;
  }
}

/**
 * Whether this browser does deflate-raw both ways. Older ones with the
 * streams only do gzip and deflate, and throw on constructing it.
 */
function canCompress(): boolean {
  try {
    new CompressionStream('deflate-raw');
    new DecompressionStream('deflate-raw');
    return true;
  } catch {
    return false;
  }
}

async function deflate(bytes: Uint8Array): Promise<Uint8Array> {
  const stream = new Blob([bytes as Uint8Array<ArrayBuffer>])
    .stream()
    .pipeThrough(new CompressionStream('deflate-raw'));
  return new Uint8Array(await new Response(stream).arrayBuffer());
}

/** The inflated bytes, or null if they're corrupt or over `MAX_PAYLOAD_BYTES`. */
async function inflate(bytes: Uint8Array): Promise<Uint8Array | null> {
  const reader = new Blob([bytes as Uint8Array<ArrayBuffer>])
    .stream()
    .pipeThrough(new DecompressionStream('deflate-raw'))
    .getReader();
  const chunks: Uint8Array[] = [];
  let total = 0;
  try {
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      total += value.length;
      // Read in chunks and stopped early, so a small link can't inflate
      // into an arbitrarily large string.
      if (total > MAX_PAYLOAD_BYTES) {
        void reader.cancel().catch(() => {});
        return null;
      }
      chunks.push(value);
    }
  } catch {
    return null;
  }
  const joined = new Uint8Array(total);
  let offset = 0;
  for (const chunk of chunks) {
    joined.set(chunk, offset);
    offset += chunk.length;
  }
  return joined;
}

/**
 * The share link fragment for `template` (without the `#`): its JSON,
 * compressed where the browser can, in URL-safe base64.
 */
export async function encodeShareFragment(
  template: PortableTemplate,
  compress = canCompress(),
): Promise<string> {
  let json = JSON.stringify({
    name: template.name,
    description: template.description,
    definition: template.definition,
  });
  // Autolinkers (GitHub's, chat apps', this app's own Markdown) leave a
  // trailing `_` out of a link as punctuation. JSON allows trailing spaces,
  // and each one changes the encoding's end, so one is added until it
  // doesn't end in `_`: about one link in 64 needs one, and the chance of
  // still needing more falls by 64 each time.
  for (;;) {
    const bytes = new TextEncoder().encode(json);
    const fragment = compress
      ? `${COMPRESSED}${toBase64Url(await deflate(bytes))}`
      : `${RAW}${toBase64Url(bytes)}`;
    if (!fragment.endsWith('_')) return fragment;
    json += ' ';
  }
}

/**
 * Copies `text` once it resolves. Through a ClipboardItem where there is
 * one, which takes the promise itself: Safari refuses a clipboard write
 * that comes after an await, once the click that asked for it is over.
 */
export async function copyToClipboard(text: Promise<string>): Promise<void> {
  if (typeof ClipboardItem === 'function' && navigator.clipboard?.write) {
    await navigator.clipboard.write([
      new ClipboardItem({
        'text/plain': text.then(
          (value) => new Blob([value], { type: 'text/plain' }),
        ),
      }),
    ]);
    return;
  }
  await navigator.clipboard.writeText(await text);
}

/** The share link for `fragment` on this instance. */
export function shareLink(origin: string, fragment: string): string {
  return `${origin}${PATHS.templatePreview}#${fragment}`;
}

/**
 * The JSON text a share link fragment (without the `#`) carries, or why
 * there is none: `link` for one that isn't a share link or is damaged,
 * `newer` for a later format's prefix, `browser` for a compressed one this
 * browser can't decompress. A `:` some apps percent-encode is accepted. The
 * prefix itself is case-sensitive, like the base64 after it.
 */
async function decodeShareFragment(
  fragment: string,
  decompresses: boolean,
): Promise<string | { code: 'link' | 'newer' | 'browser' }> {
  const damaged = { code: 'link' } as const;
  if (fragment.length > MAX_FRAGMENT_LENGTH) return damaged;
  const normalized = fragment.replace(/^(v\d+[a-z])%3[Aa]/, '$1:');
  const compressed = normalized.startsWith(COMPRESSED);
  if (!compressed && !normalized.startsWith(RAW)) {
    return /^v([2-9]|\d{2,})[a-z]:/.test(normalized)
      ? { code: 'newer' }
      : damaged;
  }
  let bytes = fromBase64Url(normalized.slice(COMPRESSED.length));
  if (bytes === null) return damaged;
  if (compressed) {
    if (!decompresses) return { code: 'browser' };
    bytes = await inflate(bytes);
    if (bytes === null) return damaged;
  }
  try {
    return new TextDecoder('utf-8', { fatal: true }).decode(bytes);
  } catch {
    return damaged;
  }
}

/** importTemplate() for a share link's fragment (without the `#`). */
export async function importShareFragment(
  fragment: string,
  defaultName: string,
  decompresses = canCompress(),
): Promise<ImportResult> {
  const json = await decodeShareFragment(fragment, decompresses);
  if (typeof json !== 'string') return { ok: false, problem: json };
  const result = importTemplateFile(json, defaultName);
  // Text that decodes but isn't JSON is a damaged link, not a damaged file.
  return !result.ok && result.problem.code === 'json'
    ? { ok: false, problem: { code: 'link' } }
    : result;
}

/**
 * The name to give an imported template: its own, or, if one of the
 * company's templates (archived ones included, since one may be restored)
 * already has that exact name, the name plus
 * `suffix` (then `suffix 2`, `suffix 3`, … while that's taken too), with the
 * name shortened to fit the length limit, since two identical names can't be
 * told apart in the meeting type picker. Null when no change is needed.
 */
export function distinctImportName(
  name: string,
  takenNames: readonly string[],
  suffix: string,
): string | null {
  if (!takenNames.includes(name)) return null;
  const taken = new Set(takenNames);
  for (let number = 1; ; number++) {
    const ending = number === 1 ? ` ${suffix}` : ` ${suffix} ${number}`;
    const room = MAX_TEMPLATE_NAME_LENGTH - Array.from(ending).length;
    const base = trimTemplateText(Array.from(name).slice(0, room).join(''));
    const candidate = `${base}${ending}`;
    if (!taken.has(candidate)) return candidate;
  }
}

/**
 * A template on its way to the new-template form, from the template list's
 * import or the preview page's Install. In memory only: the router moves
 * between pages without a reload, and a reload of the form starts it over.
 */
let pendingImport: { template: PortableTemplate; generation: number } | null =
  null;

export function setPendingImport(template: PortableTemplate): void {
  pendingImport = { template, generation: getGeneration() };
}

/**
 * The pending import, once: taking it clears it. Nothing if the tab has
 * logged out since (the login generation moved), so a template one admin
 * picked can't turn up in someone else's form.
 */
export function takePendingImport(): PortableTemplate | null {
  const pending = pendingImport;
  pendingImport = null;
  return pending !== null && pending.generation === getGeneration()
    ? pending.template
    : null;
}
