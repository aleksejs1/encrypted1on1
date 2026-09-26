# Playbook: Support, Workload & Burnout Recovery

[← Back to Playbooks](../README.md)

> **Template in encrypted1on1:** `support_checkin` ("Встреча о поддержке и нагрузке")
> **Triggers:** Energy level < 3 for 2+ cycles, severe post-incident exhaustion, signs of cynicism, or an overwhelmed engineer
> **Duration:** 45 minutes
> **Participants:** Manager & Overwhelmed Team Member
> **Recurrence Note:** Non-recurring template. Archiving automatically returns the pair's next meeting to the `regular` template.

---

## 1. Overview and Philosophy

When an engineer is exhausted or on the edge of burnout, standard status questions and superficial advice like *"just get some rest this weekend"* cause acute distress. Telling an overwhelmed person to rest while leaving 15 urgent Jira tickets hanging over their head is not empathy — it creates panic and insomnia.

### Burnout is a System Breakdown, Not a Character Flaw
According to Maslach Burnout Inventory studies, **the highest-performing, most conscientious, and most responsible team members burn out first.**

Burnout happens when high responsibility meets low control, excessive context-switching, uncompensated on-call duties, and a never-ending flood of interrupts.

Your goal in this meeting is **emergency load shedding**:
1. Remove all guilt and stigma.
2. Radically prune their task queue with your own hands.
3. Establish a protective digital boundary (quiet protocol) so their nervous system can recover.

---

## 2. Before the Meeting: Manager Homework

**Do not arrive empty-handed.** An exhausted brain suffers from decision paralysis. If you ask an overwhelmed engineer *"What do you want me to take off your plate?"*, they will feel guilty and say *"Nothing, I'll manage."*

### Pre-Meeting Checklist for the Manager
- [ ] Scan their active tickets, PRs, and meeting schedule.
- [ ] Prepare a concrete draft list of **3–5 tasks you are personally prepared to cancel, freeze, or reassign immediately**.
- [ ] Send a low-friction invitation:

```markdown
Subject: Checking in & lightening your plate

Hi! I noticed the past few weeks have been grueling, with incident fires and heavy load.
I want to do a quick 1:1 check-in — not for ticket updates or sprint tracking,
but specifically to help you dump excess ballast, push back deadlines, and protect your energy.

No preparation needed. Just bring a cup of tea/coffee.
```

---

## 3. The 4-Block Agenda (45 Minutes)

```
┌─────────────────────────────────────────────────────────────┐
│ Block 1: Validation & De-escalation (10 min)                │
│ • Name the strain, remove guilt, prioritize well-being      │
├─────────────────────────────────────────────────────────────┤
│ Block 2: Radical Triage & Backlog Pruning (15 min)          │
│ • Aggressive cut: Delete, Delegate, Defer                   │
├─────────────────────────────────────────────────────────────┤
│ Block 3: Boundaries & Quiet Protocols (10 min)              │
│ • Focus days, no-meetings policy, on-call suspension        │
├─────────────────────────────────────────────────────────────┤
│ Block 4: Recovery Blueprint & Low-Friction Cadence (10 min) │
│ • Light sprint mode (50%), asynchronous emoji check-in      │
└─────────────────────────────────────────────────────────────┘
```

### Block 1: Validation & De-escalation (10 min)
- *"I've noticed the last few weeks have been at redline: night releases, incident fallout, and nonstop tickets. How are you feeling physically and mentally?"*
- *"If you evaluate your inner battery from 1 to 10 right now, where are you?"*
- *"I want to state this clearly: your health and sustainability come first. No deadline or feature release is worth burning out over. We are going to restructure your workload today."*

### Block 2: Radical Triage & Backlog Pruning (15 min)
Open their task list together and execute triage:
- *"If we deleted three of these tickets right now with zero consequences, which ones would make you breathe the easiest?"*
- *"I am taking Project X and reassigning ticket Y to Sarah. Any objections? Done."*
- *"What meetings or discussions this week are causing the most dread or friction? I will step in and represent our team instead of you."*

### Block 3: Boundaries & Quiet Protocols (10 min)
Create hard digital and physical barriers to stop energy leaks:
- **On-Call Relief**: *"Let's take you off the pager/on-call rotation for the next two weeks and hand shifts to me or the team."*
- **Hard Stop**: *"Let's agree on a hard laptop close: no Slack, email, or PR reviews after 19:00 or on weekends."*
- **Focus Blocks**: *"I will cancel all non-essential meetings for you next week to give you 3 full days of uninterrupted quiet work."*

### Block 4: Recovery Blueprint & Gentle Cadence (10 min)
- *"Would taking 2–3 days completely off starting this Friday help more, or running on a 50% capacity 'light sprint' with only calm tasks?"*
- *"How should we check in over the next two weeks? Let's do a low-pressure async emoji check-in in chat (🟢/🟡/🔴) every other day, with zero obligation to write detailed updates."*

---

## 4. In-Platform Field Mapping (`support_checkin`)

When you create an anketa with the `support_checkin` template, `encrypted1on1` focuses specifically on energy and load shedding:

| Template Field | Who Fills It | Best Practice |
| :--- | :--- | :--- |
| **Mood & Workload** | Employee | Continues tracking the longitudinal sparklines in the Anketa List. |
| **Energy Level** (`energyLevel`) | Employee | Radio (`low`, `manageable`, `good`) and narrative for main energy drivers (`energyDrivers`). |
| **Workload Triage** (`workloadTriage`) | Employee | A structured list (`triageEntries`) to name tasks that should be paused, delegated, or cancelled. |
| **Boundaries** (`boundaries`) | Employee | Document needed protections (`boundariesNotes`) like quiet hours, meeting-free days, or on-call pause. |
| **Commitments** (`commitments`) | Manager | Specific list of responsibilities the manager takes over to unblock the engineer, with target dates. |
| **Check-in Cadence** (`checkInCadence`) | Manager | Agreed frequency and channel for low-friction, non-intrusive check-ins. |
| **Discuss** | Both | Open space for any other urgent support matters (`discuss` / `managerDiscuss`). |

---

## 5. Post-Meeting: The Shielding Protocol

### Manager Actions:
1. **Air Cover within 2 Hours**: Update the task tracker and notify product managers and stakeholders that deadlines have shifted. Take the communication pressure off the engineer's shoulders.
2. **Quiet Boundary within 24 Hours**: Remove them from on-call rotations and non-critical calendar invites.
3. **Gentle Async Check-in after 72 Hours**: Send a brief private message with no work questions:
   > *"Just checking in. How are you feeling today? Please remember: no late-night tasks!"*
