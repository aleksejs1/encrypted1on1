# The Manager's Playbook: Leading High-Impact 1:1s

[← Back to Playbooks](README.md)

> **For new team leads, engineering managers, and directors conducting 1:1 meetings.**

---

## 1. Mindset: From Status Cop to Multiplier

If you have recently stepped into leadership, your biggest temptation will be to use 1:1 meetings to find out what people are working on. **Resist this impulse with every fiber of your being.**

| The Novice Manager | The High-Leverage Leader |
| :--- | :--- |
| Uses 1:1 to check task and ticket statuses. | Tracks tasks in Jira/GitHub; uses 1:1 to check **energy, blockers, and direction**. |
| Talks 70% of the time, lecturing and advising. | Listens 80% of the time, asking open questions and taking notes. |
| Cancels meetings whenever calendar gets tight. | Treats the 1:1 slot as sacred and inviolable. |
| Saves feedback for the annual performance review. | Gives continuous micro-feedback every single cycle. |
| Makes promises in the call and forgets them by Monday. | Captures commitments in real time and tracks them until closed. |

Your true job as a manager is not to do the work, nor to police it. Your job is **to remove every obstacle standing between your team members and their best work.**

---

## 2. Before the Meeting: 15-Minute Preparation

Never enter a 1:1 completely cold. Spend 10–15 minutes preparing both in real life and inside `encrypted1on1`.

### Step 1: Check Objective Context (Without Micromanaging)
Scan the operational environment over the past two weeks:
- **Git & PRs**: Did a pull request languish in review for 5 days? Did they commit code late at night (past 22:00) or over the weekend?
- **On-call & Incidents**: Was there a harsh outage or a grueling week of pager alerts?
- **Communication**: Did you notice uncharacteristic silence on daily standups or sharp tone in Slack?
*Use this context to calibrate empathy, not to accuse.*

### Step 2: In-App Workflow in encrypted1on1
1. **Open the pair's Anketa**: Navigate to your Anketa list. If grouped by person, glance at the **mood and workload sparklines** to spot negative trends over past cycles.
2. **Read the Employee's Published Answers First**:
   - Notice their **Mood** and **Feelings** tags (e.g. *anxious*, *overwhelmed*, *frustrated*).
   - Check **What's harder than it should be** (friction) and their **Discuss** topics.
3. **Fill Out Your Manager Side**:
   - **How did the period go**: Provide a genuine bird's-eye perspective on team milestones.
   - **Feedback (what's going well, what could improve)**: Ground praise and adjustments in specific concrete observations.
   - **How can I help / what gets in the way**: Offer explicit unblocking ideas based on what they wrote.
   - **Achievements worth recognizing**: Log at least one specific contribution you noticed. *Unprompted recognition means ten times more than reacting to what they claimed.*
4. **Publish Your Side**: Both sides are now transparently visible before you dial into the call.
5. **Private Notes**: Open your **Private Notes panel** on the right side. Note down coaching cues, organizational context you cannot disclose yet, or salary considerations. *These notes remain encrypted exclusively under your private key and can never be read by the employee, the server, or company admins.*

---

## 3. During the Meeting: The Conversational Playbook

### Rule 1: The 80/20 Rule
You should speak no more than 20% of the time. If you find yourself speaking for two minutes straight, pause and ask:
> *"What are your thoughts on that?"* or *"How does that match what you are seeing?"*

### Rule 2: The 5-to-7 Second Silence Rule
When an employee finishes speaking, **count to five in your head before answering**.
In human psychology, vulnerable truths (admitting burnout, fear of failing, interpersonal friction with a colleague) usually emerge during the awkward silence *after* the initial polite answer. Give them space to fill that silence.

### Rule 3: The Powerful Question Bank
Replace dull questions with high-leverage prompts:

#### ⚡ Energy & Motivation
- *"On a scale of 1 to 5, what was your energy level this sprint? What gave you energy, and what drained it?"*
- *"Which task felt like meaningful progress, and which felt like bureaucratic friction?"*

#### 🚧 Blockers & Systemic Friction
- *"If you had a magic wand and could eliminate one tool, process, or meeting this week, what would it be?"*
- *"Where is work getting stuck between us and other teams right now?"*
- *"Is there any part of the codebase you or the team are afraid to touch?"*

#### 🎯 Strategic Clarity
- *"Do you feel clear on why our current milestone matters to the company, or does it feel like building in the dark?"*
- *"Are there any recent leadership decisions that felt confusing or didn't make sense to you?"*

#### 📈 Upward Feedback (Managing Up)
- *"What is one thing I should **start**, **stop**, or **continue** doing as your lead to better support you?"*
- *"Where am I unintentionally bottlenecking your progress?"*

---

## 4. Giving Constructive Feedback: The SBI Framework

Never give vague criticism like *"You need to communicate better"* or *"Be more proactive"*. It triggers defensiveness without offering a path to improvement.

Use the **SBI Model** (Situation — Behavior — Impact):

```
1. Situation (Anchor to time and place):
   "During yesterday's release incident review with the Product team..."

2. Behavior (Observable fact, no mind-reading):
   "...when Alex asked about the delay, you interrupted them and said the question was stupid..."

3. Impact (How it affected the team or outcome):
   "...which caused Alex to shut down, made the meeting tense, and damaged cross-team trust."

4. Alternative & Partnership:
   "Next time you feel frustrated with an untechnical question, take a breath and walk them through
   the architectural constraint calmly. How can I help you navigate those conversations?"
```

---

## 5. Wrapping Up: Execution Inside encrypted1on1

A meeting without recorded agreements is merely a nice chat that creates no real-world leverage.

### Step 1: Capture "Meeting Outcomes" Together
In the **Outcomes** section of the anketa, log mutual action items:
- Outcomes are **tactical, single-cycle commitments** (e.g. *"Schedule meeting with DevOps lead to fix CI flakiness"*, *"Review draft RFC by Thursday"*).
- **Ownership rule**: Only the person who created an item can check it off or edit it. The counterpart can add comments.
- Keep them to **1–3 high-priority commitments per person**. Avoid turning the list into a secondary backlog.

### Step 2: Review & Update Goals
If you have multi-month development goals:
- Review the **Goals** section. Add a new **Checkpoint** with status tag (`On track`, `At risk`, `Blocked`).
- Goals survive across cycles; checkpoints accumulate a complete audit trail of growth.

### Step 3: Deliver the 24-Hour "Quick Win"
As the manager, pick **one blocker** the employee mentioned (e.g. a missing software license, getting invited to an architectural committee, removing them from a pointless recurring meeting) and **resolve it within 24 hours of the meeting**.
> **Why this matters**: A 24-hour quick win demonstrates that the 1:1 actually works and that you have their back. It builds instantaneous trust.

### Step 4: Archive the Anketa
Once outcomes are agreed upon:
- Click **Archive** at the bottom of the page.
- Ensure *"Don't create the next meeting"* remains **unchecked** (unless this was an ad-hoc one-off meeting).
- The system automatically generates the next cycle's anketa, securely seals its encryption key to both public keys, and **automatically carries forward all unfinished outcomes and open goals**.

---

## 6. Manager Anti-Patterns to Avoid

| Anti-Pattern | Why It Fails | Better Alternative |
| :--- | :--- | :--- |
| **"I have nothing, you have nothing, let's cancel"** | Destroys the safety habit. Problems simmer until an unexpected resignation letter arrives. | If no urgent tickets exist, talk about career trajectory, tech debt, team culture, or skip straight to coaching. |
| **The Disappearing Manager (Forgotten promises)** | The employee realizes their manager's word means nothing; stops bringing up problems. | Unresolved outcomes carry forward automatically in encrypted1on1. Start the next cycle by reviewing your open promises. |
| **Recording the call on video** | Turns on instant self-censorship. Nobody shares vulnerable thoughts when a red "REC" dot is blinking. | Never record 1:1 calls. Rely on end-to-end encrypted notes in `encrypted1on1`. |
| **Giving unchecked promises on promotions** | Promising a pay raise or lead role before HR/budget approval destroys credibility if rejected. | Explain the company's objective competency rubric and support their growth, but never guarantee administrative outcomes unilaterally. |
