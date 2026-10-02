# Custom template portability: export file, share link, import

Closes [GitHub issue #163](https://github.com/aleksejs1/encrypted1on1/issues/163), a follow-up to
the company template library of [#133](https://github.com/aleksejs1/encrypted1on1/issues/133)
(see [`2026-09-27-custom-templates.md`](2026-09-27-custom-templates.md)).

## Problem

A company template lived only in its own company's library. An admin couldn't back one up, move it
between instances, or give it to someone at another company without retyping it.

## Decision

- **Frontend only, no schema or API change.** Export, the share link and import are built on the
  existing `GET`/`POST /api/admin/templates`. All of it is in
  `frontend/src/admin/templatePortability.ts`.
- **Export file**: a JSON envelope `{schemaVersion: 1, exportedAt, appVersion, template: {name,
  description, definition}}`, named `encrypted1on1-template-<slug>.json`. The issue's `$schema` URL
  was left out, since no schema is published there.
- **Share link**: `/templates/preview#v1z:<base64url of deflate-raw JSON>`, or `#v1r:` without
  compression where the browser has no `CompressionStream`. The template sits in the fragment,
  which browsers never send to the server. A link longer than 2048 characters still works, and
  the editor suggests the file instead. Decoding is capped at 200,000 fragment characters and
  256 KiB inflated, read in chunks, so a short link can't inflate into something huge.
- **Import accepts three shapes**: the envelope, a bare `{name, description, definition}`, and a
  bare definition (named "Imported template"). An envelope from a newer export format is refused
  with its own message. What the server would refuse, the importer refuses too: the name and
  description by the editor's rules, the definition by `validateTemplateDefinition()`. A refused
  definition is explained by its first error, in the editor's own words.
- **Every custom block id, field id and option value is regenerated** (`withFreshIds()`), checked
  against the ids already given out, then the result is validated again. Built-in blocks keep
  their `questionId`.
- **Import never saves.** The list's **Import template** button and the preview's **Install to
  company library** button both open `/admin/templates/new` pre-filled, marked as unsaved
  (leaving warns), for the admin to review and save. The template is handed over in memory
  (`setPendingImport()`/`takePendingImport()`), not in the URL. The router keeps only paths, and
  a reload of the form then starts it over, like any other unsaved new template.
- **A name another company template already has (archived ones included, since one may be restored) gets " (Imported)"** (translated), shortened to fit the
  120-character limit, with a note saying why. Two identical names couldn't be told apart in the
  meeting-type picker. The check runs in the editor, against the list it already fetches for the
  template cap.
- **`/templates/preview` is public** and needs no unlock, since a template holds nothing encrypted.
  An admin gets Install (disabled at the 50-template cap). A member is asked to pass the link to an
  admin and gets "Copy link for admin". A visitor gets a Log in link and is told to open the link
  again afterwards. Signup isn't linked: whether it's open depends on the instance, and the login
  page already offers it where it is.
- **The preview page asks `/api/me` itself** who's looking, each time the tab logs in or out: a
  401 means a visitor, and any other failure offers Retry. `checkAuth()`'s outcome alone can't
  tell a visitor from a logged-in admin whose check failed.
- **A hand-over is dropped if the tab logged out since** (the identity generation moved), so a
  template one admin picked can't turn up in another's form.
- **The share-link base64 is written by hand**, not through libsodium like `crypto/encoding.ts`:
  libsodium is loaded for unlocking, and the public preview page needs none of it.
- **The editor's preview and the preview page share `admin/TemplateQuestions.svelte`.**
- **The copied link is shown only while the form still matches it** (compared by content, so an
  edit and a reload after a save conflict both hide it). The link is written to the clipboard
  through a `ClipboardItem` holding the still-compressing promise: Safari refuses a clipboard
  write that comes after an `await`. Where there's no clipboard access (an `http://` instance),
  the link is shown in a field to copy by hand.
- **A name an earlier import already suffixed is numbered**: "(Imported) 2", and so on.
  If the company's templates can't be loaded to compare names with, the form says the name wasn't
  checked.
- **The preview page says its text comes from whoever shared the link.** It's on the instance's
  own origin, and anyone can write a link, so a name like "Security notice" mustn't read as the
  site speaking.
- **A link never ends in `_`**, which autolinkers (GitHub's, chat apps', this app's own Markdown)
  drop as trailing punctuation: the encoder adds a trailing space to the JSON until it doesn't.
- **A link in a later format (`v2…`), a definition with a later `schemaVersion` (in a link or a
  file), or a compressed link this browser can't decompress gets its own message**, not "invalid
  or incomplete".
- **`v1z:` is used only where the browser does `deflate-raw` both ways**, probed by constructing
  both streams. Older browsers have the streams but only gzip and deflate, and get `v1r:`.

## Known limitations

- **The hand-over is consumed when the form opens.** Back to the preview and Forward again
  reopens a blank new-template form, and the same happens if the session expires on the form. The
  preview's Install can just be pressed again. Keeping it in `history.state` would need the
  router to carry state, for a rare case.
- **The preview page fetches `/api/me` once more**, after `checkAuth()`'s own fetch, which doesn't
  keep the response. One extra request on a rarely opened page.

## Deviations from the issue

- **Export is in the editor only**, not also on each row of the template list. Every template,
  archived ones included, has an editor page, and the list rows already have two buttons each.
  The editor exports what's on screen, unsaved changes included, and says so.
- **The file import is a plain file picker**, not a drag-and-drop modal. The project has no modal
  component.
- **No Svelte component tests.** Vitest runs in the `node` environment with no component-testing
  library, and adding one is out of scope. The module has unit tests
  (`templatePortability.test.ts`). The UI is covered by `frontend/e2e/template-portability.spec.ts`:
  export to a file and import it back, and copy a link, then preview it as a visitor, a member and
  a second admin who installs it and finds it in the meeting-type picker. All e2e accounts share
  one company, so the "other company" of the issue's e2e plan is the same company, which also
  exercises the name-collision suffix.

## Privacy

Nothing here changes what the server stores: an installed template is a template saved through the
editor, under D1. The share link's contents never reach the server that serves the preview page.
The editor's export section warns that a template is shared as written and asks the admin to check
it for internal names first, and the preview page states that a template holds no answers or notes.
