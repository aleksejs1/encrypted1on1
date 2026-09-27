# Company-authored custom templates

## Problem

The four built-in meeting templates can't fit every manager's way of running a 1:1.
[GitHub issue #133](https://github.com/aleksejs1/encrypted1on1/issues/133) designed a company
library of custom anketa templates, written by company admins and usable by any member. It
shipped in five issues:

1. [#140](https://github.com/aleksejs1/encrypted1on1/issues/140) (P1): a "Next meeting type"
   picker in the archive form, for the built-in templates.
2. [#141](https://github.com/aleksejs1/encrypted1on1/issues/141) (C1): the template definition
   schema, its two lockstep validators and the renderer.
3. [#142](https://github.com/aleksejs1/encrypted1on1/issues/142) (C2): the library's storage and
   API, with immutable versions.
4. [#143](https://github.com/aleksejs1/encrypted1on1/issues/143) (C3): the admin editor.
5. [#144](https://github.com/aleksejs1/encrypted1on1/issues/144) (C4): using templates in
   anketas.

This record lists the design's decisions (#133 §11) and where the shipped code differs. The
details of each part are in `docs/history.md`.

## Decisions

- **D1, the plaintext library** (confirmed by the maintainer, 2026-09-24). Template names,
  descriptions and questions, in every version, are stored unencrypted, readable by every
  company member, the server and, on Cloud, the operator. They're company configuration, like
  the built-in wording in the source. Encrypting them would need a company-wide key sealed to
  every member, with its own distribution, revocation and recovery. Documented as the second
  plaintext exception in `CLAUDE.md`, `docs/encryption.md`, `docs/architecture-invariants.md`
  §1 and ADR 1. The editor warns about it permanently.
- **D2, admins only.** Only company admins write templates. Manager-owned personal templates
  are a follow-up (F1) needing their own plaintext decision.
- **D3, one field per custom question**, plus side-bound built-in question blocks from an
  append-only allowlist, reused with their translations, so trends and the Report keep working.
- **D4, a plaintext reference to an immutable version.** An anketa stores
  `customTemplateVersion` (with `templateKey: 'custom'`), set if and only if the key is
  `'custom'`, enforced by the `Anketa` constructor, which also checks the version's company. The
  server can therefore see which company template, by name, each anketa uses, which reveals
  more than a built-in key does. An encrypted per-anketa snapshot was rejected: the server
  couldn't validate it, so a modified client could put arbitrary questions on the counterpart's
  page; a malformed successor could wedge a pair's chain; its length would identify the
  template anyway; and create and archive would need client crypto and fallbacks.
- **D5, custom templates recur** on their latest version, and fall back to Regular once
  archived. `AnketaLifecycleService::defaultNextTemplate()` is the one place this rule lives. It
  returns the template's id, not the anketa's version, so the successor gets the version current
  at archive time.
- **D6, archive only.** No hard delete of templates or versions (F3), so a version an anketa
  references can never dangle.
- **D7, the list shows the template's name** for an open custom anketa: the name of the
  version it was created on.
- **D8, a "Next meeting type" picker at archive** for every template (confirmed by the
  maintainer, 2026-09-24). The per-template recurrence map becomes the default, not a fixed
  rule, which amends the career-growth and support-check-in decision records.

## How C4 fits together

- **Create.** `POST /api/anketas` takes `templateKey: 'custom'` with `customTemplateId`, and the
  server resolves the template to its current version. A missing, archived or other company's
  template gets the same 422 `template_unavailable`, returned, not thrown, since
  `JsonExceptionListener` would turn a thrown 422 into a 400 without the `code`. The create page
  then reloads the list, resets to Regular and keeps everything else.
- **Archive.** `nextTemplateKey: 'custom'` with `nextCustomTemplateId` picks a company template;
  without them the server's default applies, which may itself be a company template. Either way
  it's resolved before anything is mutated, so a 422 leaves the anketa open. The page then
  refreshes only the picker's default, choice and list, and the next click archives.
- **The picker's encoding.** One string per option, a built-in key or `custom:<templateId>`
  (`anketa/templateChoice.ts`). The request fields are sent only when the choice differs from
  the default, and the default is always an option ("Same template as now" if it isn't in the
  fetched list).
- **The page.** A custom anketa's definition is fetched by version id, validated again and
  rendered with `questionsFromDefinition()`. Until it's there, or if it can't be loaded, the
  answer sections aren't shown, and a banner offers Try again; the archive card still works.
- **Payloads.** The detail carries `customTemplateVersionId`, `customTemplateName` and
  `nextCustomTemplateId`; the list row carries `customTemplateName`; the live-state poll carries
  none of them. The list and bulk query fetch-joins the version and template.
- **Export.** Each anketa gains `templateKey` and `formVersion`; a custom one gains its
  version's `template: {name, definition}`, fetched once per version, or
  `templateUnavailable: true` if that fetch fails.

## Differences from the design

- `AnketaPresenter::summarizeForList()` adds `customTemplateName` to the list row, instead of
  `list()` doing it inline.
- `Anketa::TEMPLATE_KEYS` spells `'custom'` as a literal rather than
  `self::CUSTOM_TEMPLATE_KEY`, because the frontend test that cross-checks the list reads the
  PHP source as a literal array.

## Known edges

- An SPA tab loaded before C4 is deployed renders a `custom` anketa as Regular, through
  `templateFor()`'s fallback; answers typed there would be keyed by Regular's field ids. The
  window is one page reload. Accepted in #144, not engineered around.
- An admin archiving a template between the archive request's resolution and its flush still
  gets the successor created on that template; its next archive then defaults to Regular (#133
  §8.1).
