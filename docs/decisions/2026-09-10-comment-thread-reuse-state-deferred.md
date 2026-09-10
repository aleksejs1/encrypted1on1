# Defer resetting CommentThread's local state on component reuse

## Problem

`CommentThread.svelte` keeps several pieces of purely local `$state` (`expanded`, `text`,
`editingId`/`editText`, `confirmingDeleteId`, and their busy/error flags). None of it is reset when the
component's props change — only at mount. Each instance sits inside a `{#each ... (id)}` block keyed by a
static id (a question field's id, e.g. `growthEntries`, is identical across every anketa — see
`frontend/src/anketa/questions.ts`), and Svelte's keyed `{#each}` reuses a component instance rather than
remounting it whenever the same key reappears in a later render of the same list.

`Anketa.svelte`'s own `load()` already documents and works around exactly this hazard for its own state
(`Anketa.svelte:183-188`: *"The router reuses this component instance across a same-page navigation to a
different anketa id... an answers-edit session left open on the previous anketa must not leak into the
next one's otherwise-fresh state below."*) — but nothing resets `CommentThread`'s own internal state the
same way, and it has no prop/mechanism the parent could use to do so externally. Raised by an independent
code-review pass during the `CommentThread` default-expand change (#51/PR #52), then filed separately as
[#53](https://github.com/aleksejs1/encrypted1on1/issues/53) since it was pre-existing and out of that
change's scope.

## Decision

**Defer. No code change.** Confirmed there is currently no navigation path that actually reaches this: no
in-app link or `navigate()` call takes a user from one anketa directly to another while staying mounted on
the same `/anketas/:id` route (the only `navigate()` targeting an anketa URL is from `/anketas/new`, a
different route branch; every anketa-to-anketa link elsewhere — `AnketaList.svelte`, `AppHeader.svelte` —
is a plain `<a href>`, which causes a full page reload and a fresh `AnketaPage` mount). Every real
navigation between two different anketas today fully remounts the page, so this gap never triggers.

Per CLAUDE.md's working-style rule ("design the full state space before writing the fix... before
broadening what a shared invalidation/cleanup function does, grep every existing call site"), the two code
fixes considered both carry real cost with no reachable bug to justify it today:

- Wrapping the anketa-content section of `Anketa.svelte` in `{#key id}` would force every descendant
  (`CommentThread` included) to remount on an anketa-id change — structurally sound (no per-field state
  list to keep in sync as `CommentThread` grows new local state later) but touches a broad chunk of the
  page's template, and would need auditing every sibling child (`AnswerField`, etc.) for what forced
  remounting means for it.
- Giving `CommentThread` an identity prop and an `$effect` that resets its own state by hand is the
  narrower diff, but is exactly the "list every piece of state and reset it one field at a time" pattern
  the 11-round multi-tab-unlock incident (`docs/decisions/2026-08-28-multi-tab-unlock-state-machine.md`)
  showed going wrong — every future field added to `CommentThread`'s local state needs a matching line
  added here, or the bug quietly reappears.

Building either now, with no real transition to design against, would be exactly the "speculative
flexibility for requirements that don't exist yet" CLAUDE.md's non-negotiable-constraints section warns
against — there's no concrete transition shape (a "next/previous cycle" link? a carousel switcher?) to
verify a fix against yet, only a hypothetical one.

## When to revisit

The moment any feature adds a same-page anketa-to-anketa transition (the "next/previous cycle" quick link
example above, or anything similar), re-open this: that feature's own implementation is exactly where the
right remount/reset boundary becomes concrete and testable, rather than guessed at now.

## Verification

N/A — no code changed. The "not reachable today" claim was verified by grepping every `navigate(`/`<a
href="/anketas` call site in `frontend/src` and confirming none transitions between two different anketa
ids without an intervening full page load.
