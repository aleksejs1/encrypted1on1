# Playbook: The Regular Bi-Weekly Sync

[← Back to Playbooks](../README.md)

> **Template in encrypted1on1:** `regular` ("Обычная встреча")
> **Cadence:** Every 1–2 weeks
> **Duration:** 30–45 minutes
> **Participants:** Manager & Direct Report

---

## 1. Overview and Purpose

The regular bi-weekly sync is the operational heartbeat of the team. It is **not** a status meeting to recite Jira tickets. It is a predictive radar designed to detect cognitive overload, eliminate organizational friction, align everyday engineering with product strategy, and calibrate bilateral feedback before small irritations morph into resignations.

### Why the 2-Week Cadence Matters
- **1 week**: Great for onboarding or crisis phases, but can feel too frequent for autonomous senior engineers.
- **2 weeks**: The sweet spot for high-output engineering teams. Long enough for meaningful progress to occur, yet short enough that blockers never fester for more than 10 business days.
- **Monthly or longer**: Too slow. Problems compound, and the meeting degenerates into an overwhelming backlog review.

---

## 2. The 4-Pillar Agenda (35–45 Minutes)

```
┌─────────────────────────────────────────────────────────────┐
│ 1. Energy Pulse & Resource Calibration (5–7 min)           │
│    • Mood, feelings, and workload trend                     │
├─────────────────────────────────────────────────────────────┤
│ 2. Process Friction, Blockers & Tech Debt (15 min)          │
│    • "What's harder than it should be", dependency traps    │
├─────────────────────────────────────────────────────────────┤
│ 3. Product Context & Strategic Alignment (10 min)           │
│    • Why this sprint matters, leadership decisions          │
├─────────────────────────────────────────────────────────────┤
│ 4. Two-Way Feedback & Bilateral Commitments (10 min)        │
│    • Manager unblocking, shared action outcomes             │
└─────────────────────────────────────────────────────────────┘
```

### Pillar 1: Energy Pulse & Resource Calibration (5–7 min)
**Objective:** Assess battery level before diving into operational topics. Avoid generic *"How are you?"* questions.

- **Key Questions:**
  - *"How is your energy level this week on a scale of 1 to 5? What energized you most, and what caused the most exhaustion?"*
  - *"Did your workload feel like creative momentum or like spinning your wheels in unnecessary chores?"*
- **What to listen for:** An energy level below 3 for two cycles in a row is an early warning sign of burnout or hidden team conflict.

### Pillar 2: Process Friction, Blockers & Tech Debt (15 min)
**Objective:** Uncover systemic friction and bottlenecks slowing down delivery.

- **Key Questions:**
  - *"Where is the biggest bottleneck in our team's processes or dependencies on other teams?"*
  - *"Is there an area of our codebase or infrastructure that everyone is scared to touch, and why?"*
  - *"What can I personally remove from your path this week so you can get back into deep work?"*
- **What to listen for:** Chronic CI/CD failures, delayed code reviews (PRs stuck for 4+ days), missing PRDs, or bureaucratic approval chains.

### Pillar 3: Product Context & Strategic Alignment (10 min)
**Objective:** Connect daily engineering work with business impact. Strong engineers disengage when they feel like disconnected "ticket movers".

- **Key Questions:**
  - *"Do you feel clear on how your current tasks move the needle for our customers and business?"*
  - *"Were there any recent company or leadership decisions that felt confusing, contradictory, or demotivating?"*
- **What to listen for:** Cynicism or alienation from product goals.

### Pillar 4: Two-Way Feedback & Commitments (10 min)
**Objective:** Close the loop on past promises, exchange feedback, and lock in commitments for the next cycle.

- **Key Questions:**
  - *"Let's check last cycle's outcomes: did we both deliver what we promised two weeks ago?"*
  - *"What could I have done differently over the last two weeks to better shield or support you?"*
  - *"What are the 1–2 key outcomes we are committing to before our next meeting?"*

---

## 3. In-Platform Field Mapping (`regular`)

| Platform Field | Who Fills It | Best Practice & Purpose |
| :--- | :--- | :--- |
| **Mood & Feelings** | Employee | Select mood (`Good`, `Neutral`, `Bad`), trend (`Better`, `Same`, `Worse`), and choose emotional tags (`calm`, `frustrated`, `overwhelmed`, etc.). |
| **Workload** | Employee | Indicate capacity (`Too much`, `Just right`, `Too little`) and trend. Vital for early triage. |
| **Growth** | Employee | Append-only dated log of learnings, technical discoveries, or insights. Fed into period reports. |
| **What's harder than it should be** | Employee | Process friction, slow tooling, technical debt, or interpersonal hurdles. |
| **Achievements** | Both sides | Employee logs their wins; manager logs contributions worth recognizing. |
| **Discuss** | Both sides | Running agenda of topics to cover in the call. |
| **Period summary & Feedback** | Manager | High-level synthesis of the past cycle; specific, actionable guidance. |
| **Support / Unblocking** | Manager | Explicit commitments on how the manager will unblock the employee. |
| **Outcomes** | Collaborative | Tactical action checklist. Only the author can check off an item. Unresolved items carry forward automatically upon archiving. |

---

## 4. Key Anti-Patterns

- **"I have nothing, you have nothing, let's cancel"**: The single most destructive habit. Skipping meetings teaches the employee that their relationship with leadership is optional. Issues fester in silence until an unexpected resignation occurs.
- **Sprint Demo / Jira recitation**: If the employee is reading ticket descriptions from Jira, interrupt gently: *"The ticket status is clear in Jira. Tell me: why did this take so much energy, and what can we do to make the next one smoother?"*
- **The Empty Notebook (Broken Manager Promises)**: If a manager listens to complaints, nods, promises to help, and forgets about it by next week, psychological safety collapses.

---

## 5. Follow-Up Checklist

- [ ] **Within 5 minutes:** Document 1–3 agreed action items in **Meeting Outcomes**.
- [ ] **Within 24 hours:** Manager delivers on a "Quick Win" (e.g. unblocking a dependency or sending an intro).
- [ ] **Archive:** Click **Archive** in `encrypted1on1` to auto-schedule the next meeting and carry forward open outcomes.
