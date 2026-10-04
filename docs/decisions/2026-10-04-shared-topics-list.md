# A shared "Topics to discuss" list

Closes [GitHub issue #206](https://github.com/aleksejs1/encrypted1on1/issues/206), the last part of
the product-adoption work ([#213](https://github.com/aleksejs1/encrypted1on1/issues/213)).

## Problem

Answers are private until a side publishes them. So the topics one person jots down during the
week are invisible to the other until "Publish": at the start of the meeting a manager may see
nothing of what the employee wants to talk about. A shared document doesn't have this problem,
and it is what this product competes with.

Showing part of a draft before it is published would break the "drafts are private" model. A
list that both sides edit at any time, encrypted under the meeting key and live-synced, already
exists: outcomes. The topics list is a second one of those.

## Decision

- **Storage.** `Anketa::$topicsBlob` and `$topicsVersion` (SQLite and MySQL migrations), a list of
  `TopicItem` (`id`, `authorId`, `text`, `discussed`, `createdAt`) encrypted under the meeting
  key, in the same envelope as outcomes. It is in the detail payload, the bulk payload the data
  export reads, and `topicsVersion` is in the live-state poll.
- **Saving.** `PUT /api/anketas/{id}/topics` writes through
  `AnketaRepository::saveTopicsIfVersion()`: one conditional `UPDATE … WHERE topicsVersion =
  :expected AND archivedAt IS NULL`, the same statement as the "discussed" ticks (#168), each
  written out as its own literal query. A stale version gets a 409 with the current blob and
  version; an archived meeting gets a 409 without them.
- **No draft phase.** Either side adds a topic at any time, before or after publishing, and the
  other side sees it within one poll. The card says so, because it is the one place on the page
  where typing something shares it at once.
- **Who changes what.** Only the author edits or deletes a topic, like an outcome. Either side
  ticks a topic as discussed, unlike an outcome's "done", which only its author ticks: a topic
  is talked through by both people, and the meeting usually runs on one person's screen. As
  everywhere else, the client enforces this, not the server, which can't read the list.
- **Frozen once archived.** The conditional `UPDATE` refuses the save, and the card drops its
  add form and buttons. Outcomes stay editable after archive; topics are an agenda, and an
  agenda for a closed meeting has no use.
- **Carry-forward.** On archive, and when a meeting is created by hand after the pair's last one
  was archived, the topics not ticked as discussed are re-encrypted under the new meeting's key
  by the browser and sent as `topicsBlob`, exactly like unchecked outcomes
  (`carryForwardBlob()` in `blobSync.ts` is now the one implementation for both). A one-off
  meeting gets none and passes none on, like every other carry-forward
  (`AnketaLifecycleService::createWithCarryForward()`).
- **Placement.** The first card under the meeting header, above both sides' answers. An archived
  meeting with no topics shows no card, so meetings from before this feature don't gain an
  empty one.
- **The duplicate questions go away, for new meetings only.** `Anketa::CURRENT_FORM_VERSION` is
  now 3. From that version on `getQuestionsForSide()` leaves out `discuss` and `managerDiscuss`
  for every built-in template; this is done in that one function, so all four templates are
  version-aware without each needing its own rule. A meeting created at version 1 or 2 keeps
  the blocks and what was answered in them.
- **Company templates keep them.** `discuss` and `managerDiscuss` stay in the allowed built-in
  question ids on both sides of the lockstep validator, because every stored definition is
  checked against that list: removing them would stop meetings on such a template from opening,
  stop the template from being re-saved, and make import reject it. A company template that
  lists them shows them at any form version (`questionsFromDefinition()` doesn't go through
  `getQuestionsForSide()`). A new template's Regular prefill no longer includes them
  (`RETIRED_BUILTIN_QUESTION_IDS`), so it matches what a new Regular 1:1 looks like. The
  editor's "add question" menu still offers them: hiding them there, which the issue allowed
  "at most", was tried and dropped in review, because an admin who removed the block from an
  existing template could not put it back. The choice stays with the admin.
- **Demo data.** The demo meetings are created at the current form version, so the seeded
  "what else" lines moved into the topics list: `demo-seed.json` was regenerated for all six
  locales with `topicsBlob`/`topicsVersion` per cycle, which `app:reset-demo-data` restores.
  The current cycle starts with one topic carried forward. `generate-doc-screenshots.mjs`
  adds its two such lines as topics too.

## The issue's open questions

- **Comments on topics: not in this version.** A topic is one line and the conversation about
  it is the meeting. Leaving them out also keeps the card out of the page-wide comment-thread
  busy gate, so nothing else on the page can hold back a topics refresh.
- **Topics and the per-block "discussed" ticks (#168) are independent.** Both are meeting aids
  with the same look (a tick, a dimmed line), but they are separate lists with separate
  versions. A topic's tick is a field of the topic, in `topicsBlob`; a question block's tick is
  in `discussedBlob`. Nothing derives one from the other.

## Client design

The card is `frontend/src/anketa/AnketaTopics.svelte`; the list operations are pure functions in
`topics.ts` (`addTopic`, `editTopic`, `deleteTopic`, `setTopicDiscussed`); saving is
`topicsSync.ts`.

- **One `TopicsSync` per loaded meeting**, created by `load()` once the key and list are known
  and bound to that meeting's id and key, like `DiscussedSync` (#168). The card isn't rendered
  until it exists, so nothing typed there can be saved to another meeting or under another key.
  Its states are idle, saving (maybe with more changes queued) and stopped (archived, or the
  page left).
- **One save at a time, each starting from the last saved list**, with no fetch first. A
  stale-version 409 shows the list its body carries and reapplies the change to it, up to five
  times. `setTopicDiscussed()` sets a state rather than toggling, so a reapplied tick can't
  undo the counterpart's same tick. Several ticks in a row never conflict with each other. A
  change that leaves the list as it is (the counterpart ticked the same topic first) isn't
  saved at all, so it bumps no version.
- **Each change has its own promise.** Unlike a "discussed" tick, a change can fail on its own
  terms (the topic it edits was deleted meanwhile), so the card reports each one. Nothing is
  shown before it is saved, except a tick: the box shows the intended state at once
  (`pendingDiscussed`) and goes back if its save fails. The box is not disabled while its save
  runs, so it keeps keyboard focus (#149, #151) and can be changed again; only a topic's latest
  change clears its pending state.
- **The live-update poll hands a newer list to the sync**, which ignores it while a save is
  under way. An open edit form is no reason to hold a refresh back: it keeps its text while
  the list around it changes.
- **The card's open action is one value**, `TopicAction`: `idle | adding | editing(id, saving)
  | confirmingDelete(id, deleting)`, local to the card. What the card acts on is derived from
  it: none once archived, and none for an edit or delete whose topic is no longer in the list,
  so a row removed from another tab can't leave the card stuck. The page keys the card by
  meeting id.
- **The archived 409 stops the sync and moves the page to archived at once**, exactly as
  `DiscussedSync` does, so the page has one rule for both. An in-between version asked
  `live-state` first; that left `handleArchive()` reading `archived` before the answer came,
  and sent every queued change to be refused too.
- **Archiving waits for the sync, locks the card, and names the topics version it carried
  from.** `settled()` is true at once when nothing is pending, whatever happened earlier; if a
  change under way at the click fails, the click doesn't archive, like after a failed
  "discussed" save, since the tick would otherwise be frozen as not made. The card's controls
  are disabled while the archive request runs. The carry-forward is built from the sync's
  list, and the request carries that list's `topicsVersion`. `markArchivedIfOpen()` takes it
  as one more condition of its `UPDATE`, so no topics save can land between the check and the
  archive. If the list has moved on (the list on screen can be a poll interval behind), the
  archive is refused (`AnketaTopicsChangedException`, its own exception, so a refusal can't be
  mistaken for "already archived") with the same body as a refused topics save and the meeting
  stays open;
  the page takes that list and archives again, up to three times. A fresh read just before
  the request was tried first and left a window one request long. Only checked when a
  successor is created; a client that sends no version isn't checked.
- **An add is idempotent.** The topic is made once (`newTopic()`), outside the queued change,
  and `addTopic()` skips an item the list already has; a retry of the same text reuses the
  item. So a save that landed with its response lost can't add the topic twice. A retry with
  different text is a new topic: treating it as a rewording could overwrite the first one.
- **Topic text renders inline Markdown** (`InlineMarkdown.svelte`, #165), like the "What else
  to discuss" entries it replaces.

The first version saved through the page's shared `updateField()` (fetch, apply, save, retry
once), with a hand-rolled queue added in review. Four review rounds each found another race in
it (parallel saves exhausting the one retry, a queued save outliving a page switch, a stuck
edit form), which is what a dedicated per-meeting object was for in #168. The 409-body parser
all three now share is `versionConflictBody()` in `blobSync.ts`.

Two things changed in `updateField()` along the way and were kept, for its three remaining
callers:

- It is bound to the meeting id and key it started with. It used to read `id` again after its
  first request, so after a same-page switch to another meeting it could save to, and apply its
  result on, the wrong one (a gap #168 recorded and left open).
- A 409 without a version in its body is no longer retried as a version conflict. The archived
  409 of `goal-checkpoints` used to be retried with an undefined version and surface as a
  validation error instead of "This 1:1 is archived".

## Accepted limitations

- **A tab still running the previous release archives without the topics.** Carry-forward is
  the archiving browser's job (the server can't re-encrypt), so an old tab sends no
  `topicsBlob` and the next meeting starts without the open topics. They stay in the archived
  meeting. Only possible for tabs left open across this release.
- **The list is trusted as written.** Either participant holds the meeting key and can write any
  blob; a malformed one fails to decrypt or parse like a malformed outcomes blob would. No worse
  than what that participant can already do to outcomes and comments.
- **The ciphertext is not padded**, unlike the "discussed" ticks: those are a short list of public
  ids, where size alone gives the content away. Topics are free text, like outcomes and comments.
  See `docs/encryption.md` for what the server can observe.
- **A tab still running the previous release shows "What else to discuss" on a new meeting**,
  since the rule is in the client. Whatever is answered there is stored but not shown by the
  current client. The same is true of any form-version change; a reload fixes the tab.
- **`TopicsSync` and `DiscussedSync` are two classes with the same shape** (queue, conflict
  reapply, `settled()`, `stop()`). They differ in what a change is (a function that can fail,
  with its own promise, against a set-to-state map shown before it's saved), and one generic
  queue over both was judged harder to read than two small ones. They share the 409 parser.
- **A change that fails for a harmless reason still stops an archive clicked while it was
  under way**, once: `settled()` doesn't tell "the topic was deleted meanwhile" from a failed
  save. The card says what happened and the next click archives. `DiscussedSync` does the same.
- **`AnketaTopics.svelte` repeats much of `AnketaOutcomes.svelte`'s row markup and styles.** A
  shared list-row component would mean rebuilding the outcomes card, with its comment threads
  and page-bound state, inside this change; left for a change of its own.
- **Text typed into an open topic edit is dropped if the meeting is archived meanwhile**, by
  either side, with no warning: the same as an outcome's open edit, and unlike an answers edit,
  which blocks this tab's Archive button.

## Verification

- `frontend/src/anketa/topics.test.ts`: each operation's success / other items untouched / wrong
  author / wrong id cases, and the carry-forward with real keys (only undiscussed topics,
  readable under the new key and not the old).
- `topicsSync.test.ts`: queued saves run in order with no conflicts among themselves; after a
  conflict the counterpart's new topic is kept and a tick isn't undone; a change that no longer
  fits is rejected with the current list shown and the queue carries on; repeated conflicts
  give up; the archived refusal is reported without a retry; the poll's list is ignored while
  saving or when not newer; `settled()` and `stop()`.
- `questions.test.ts`, `templateDefinition.test.ts`, `templateEditor.test.ts`: every built-in
  template keeps its discuss blocks before version 3 and drops them from it on; a company
  template's `discuss` block resolves at every version; a new template's prefill leaves the
  retired questions out, the editor still offers them, and a definition with them is valid.
- Backend: the endpoint's success, stale-version 409, archived 409, malformed payload and
  outsider cases; the repository's once-per-version, archived and own-row cases, and that topics
  and "discussed" versions don't affect each other; carry-forward on archive and create, dropped
  for a one-off; an archive naming a stale topics version is refused with the meeting still open
  and no successor, then goes through with the current one; the privacy black-box test round-trips a real encrypted topics blob.
- `frontend/e2e/dual-actor-anketa.spec.ts`, two real browser sessions against the e2e stack: a
  topic added by one side appears for the other without a reload, with neither side published;
  edit, delete and ticks sync both ways; two ticks at the same moment both land; archive freezes
  the list; a topic the archiving tab never heard of makes the archive be refused once and then
  succeed with it carried; the successor has only the topics not ticked, readable by both. A second test turns a
  meeting into a form-version-2 one in the database and checks it shows "What else to discuss",
  takes an answer there, and shows it to the counterpart.
