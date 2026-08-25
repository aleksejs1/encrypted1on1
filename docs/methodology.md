# The 1:1 methodology

This describes the *meeting practice* the app is built around — why 1:1s matter, how a cycle is meant to be conducted, and what each question is actually asking. For the mechanics of the screens that support this (draft autosave, publishing, comments), see [user-flow.md](user-flow.md). For why goal titles specifically are the one field the server can see, see [encryption.md](encryption.md).

## Why 1:1s, and why this format

A regular 1:1 between a manager and their report is one of the highest-leverage habits a team can keep — it's where small problems (workload creeping up, a blocker no one's mentioned yet, a quiet drop in morale) get caught while they're still small, and where career growth actually gets discussed instead of assumed. Most teams that already do this well aren't using a dedicated tool for it — they're using a shared Notion page or Google Doc, because that's low-friction and good enough to start.

That's the actual competition this app has in mind, more than other HR software. Encryption alone isn't a compelling reason to switch away from a doc people already like — the real case for switching is **structure that a free-form document can't give you for free**: periodicity that recreates itself, reminders that fire on their own, goals and unresolved commitments that carry forward from one cycle to the next without anyone having to remember to copy them over. Privacy is what makes that structure safe to trust with things people wouldn't otherwise write down — the two only work together.

## The cycle, end to end

An anketa isn't a form you fill out *during* the meeting — it's built around a specific sequence, and the sequence is the point:

1. **The employee fills out their side and publishes it before the meeting.** This isn't a formality — publishing early is what makes step 2 possible.
2. **The manager reads the employee's already-published side, then fills out and publishes their own.** Going in having actually read what the employee wrote turns the meeting from "let's catch up" into a real, prepared conversation.
3. **The meeting itself is a conversation, not a form review.** The employee walks through their side and talks about it; then the manager does the same with theirs. The anketa is the agenda and the shared memory of what was said — it doesn't replace the conversation, it makes the conversation better prepared.
4. **Outcomes get captured together, then the anketa is archived.** Whatever was actually agreed — commitments, follow-ups — goes into the shared outcomes list *during or right after* the meeting, since that's a negotiated result of the conversation, not something either side could have written alone beforehand.

Answers stay editable for the entire period between meetings, right up until published — there's no "too early to start" and no artificial cutoff a few days before the meeting. Nothing about the format assumes you'll sit down and fill the whole thing out in one sitting the night before.

## Why the anketa stays open the whole cycle

Several fields are lists you add dated entries to over time, not a single text box: what you learned or discovered (growth), things worth calling out as achievements, and topics you want to make sure get discussed. These are append-format on purpose — the idea someone learned something worth noting is far easier to capture in the moment it happens than to reconstruct from memory the night before a meeting that might be weeks away. The same applies to the manager's "achievements worth recognizing" for their report.

Other fields are deliberately the opposite — a single snapshot, not a log: mood and workload are asked as "how do you feel *right now*, and compared to last time" — a trend line across an anketa's whole lifetime wouldn't mean much, since what matters is where things stand as the meeting approaches, not a diary of every day in between.

## What each question is asking, and why

### Employee side

- **Mood** — how you're feeling right now, and whether that's better, worse, or about the same as last time, plus an optional note. A direct, low-friction check-in — the trend across meetings is often more informative than any single answer.
- **Feelings** — a short checklist (excited, anxious, confident, overwhelmed, motivated, frustrated, grateful, proud, calm, stressed, bored, lonely) plus an optional note. More specific than "mood," and easier to select from a list than to put into words unprompted — it exists so a real emotional signal doesn't get lost simply because naming it felt awkward. The checklist itself is versioned (`Anketa::CURRENT_FORM_VERSION`) so it can grow over time without changing what an already-created anketa's answers mean — an anketa created before a given option existed simply never offered it, rather than retroactively looking like the option was seen and left unchecked.
- **Workload** — how much you have on your plate right now, and whether it's trending up or down, plus a note. Catching a workload problem while it's still a trend, not yet a crisis, is one of the most concrete things a regular 1:1 can do.
- **Growth. What did you learn, discover, take away?** — an ongoing log of things learned, not a single reflection written under deadline. Growth is usually made of small moments that are easy to forget happened at all by the time a performance review rolls around — this is where they get kept.
- **What's harder in my work than it should be** — friction: the things that are quietly costing time or energy. Naming this explicitly, as its own question rather than hoping it surfaces in conversation, is what turns a vague sense that something's off into something a manager can actually act on.
- **Achievements** — a running list, not a last-minute reconstruction. Doubles as direct input to the [period report](user-flow.md#reports) when performance-review time comes, so recognition doesn't depend on anyone's memory being good months later.
- **What else to discuss** — an open slot for anything the fixed questions above didn't anticipate. The structure above is deliberately not assumed to cover everything worth talking about.

### Manager side

- **How did the period go since the last meeting** — the manager's own read on the period, written independently before seeing anything beyond what the employee has already published. A genuine perspective, not a reaction to reading the employee's answers a minute earlier.
- **Feedback: what's going well, what could improve** — direct, two-sided feedback, not saved up for a formal review months away. Regular small feedback is what actually changes behavior; feedback that only shows up once or twice a year rarely does.
- **How can I help / what gets in the way** — turns the manager's role in the conversation from "evaluate" to "unblock." Asked as its own explicit question so it doesn't get skipped in favor of the more evaluative ones above it.
- **Achievements worth recognizing** — the manager's own log of things the employee did well, kept independently of the employee's own achievements list. The same thing can be worth noting from either side, and recognition means more when it wasn't prompted by the employee mentioning it first.
- **What else to discuss** — the manager's version of the employee's open slot above, for the same reason.

## Outcomes vs. goals — tactical vs. strategic

The anketa ends up producing two different kinds of forward-looking record, and it's worth keeping the distinction clear:

- **Outcomes** ("meeting outcomes") are tactical, one-cycle commitments — things agreed *at this specific meeting*, checked off once done, and carried forward to the next anketa only if still unresolved by the time this one archives. They exist because they're a result of the conversation, not something to publish unilaterally.
- **Goals** are strategic and multi-cycle — they persist across as many anketas as it takes until marked achieved or cancelled, accumulating a history of progress checkpoints along the way. A goal set six months ago and still open today is exactly the expected case, not a stale leftover.

Both follow the same ownership rule (only whoever created an item can edit or close it; the other side can comment) — see [user-flow.md](user-flow.md) for how that plays out in the interface, and [encryption.md](encryption.md) for why a goal's title/description/status specifically are the one thing on this whole page the server can actually read.
