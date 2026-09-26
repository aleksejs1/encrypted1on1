# Playbook: The First 1:1 & Onboarding

[← Back to Playbooks](../README.md)

> **Template in encrypted1on1:** `onboarding` ("Первая встреча / Онбординг")
> **Timing:** First 1–2 weeks of working together
> **Duration:** 45–60 minutes
> **Participants:** Manager & New Team Member (or New Lead & Existing Team Member)
> **Recurrence Note:** Non-recurring template. When archived, the next cycle automatically switches to the `regular` template.

---

## 1. Overview and Philosophy

The first 1:1 lays the psychological foundation for the entire working relationship. It is **not** a competence test, a quiz on documentation, or an evaluation.

### The Newcomer's Hidden Anxiety
When a new employee sees an empty calendar invitation titled *"1-on-1 with Manager"*, their threat-detection radar spikes:
- *"Am I doing something wrong?"*
- *"Are they testing me?"*
- *"Will this be an interrogation?"*

If you don't explicitly demystify the meeting up front, the employee will spend the entire hour in defense mode, offering polite, guarded answers. Your primary goal is to **defuse this anxiety and establish psychological safety.**

---

## 2. Before the Meeting: Defusing the Invitation

**Never send a blank calendar invite.** Send an invitation with clear framing:

```markdown
Subject: Welcome & First 1:1 — Getting aligned & setting our rhythm

Hi! This is our very first 1:1 meeting.

The goal of this call is to get to know each other, explain how our regular syncs work,
figure out how we can best collaborate, and answer any early questions you have.

There are NO status reports, NO technical quizzes, and NO grading here. This is your time.
If there are any topics you'd like to touch on, feel free to add them to our encrypted anketa!
Looking forward to chatting over coffee/tea.
```

### Manager Pre-Flight Checklist
- [ ] Check repository, cloud, and chat permissions (avoid spending the meeting debugging SSO).
- [ ] Verify an onboarding buddy has been assigned.
- [ ] Ensure a quiet, confidential setting (never conduct a first 1:1 in a noisy open space or cafe where colleagues can overhear).

---

## 3. The 3-Block Agenda (45–60 Minutes)

```
┌─────────────────────────────────────────────────────────────┐
│ Block 1: Working Agreement & Psychological Safety (10 min)  │
│ • Establish the 80/20 rule and protected calendar slot      │
├─────────────────────────────────────────────────────────────┤
│ Block 2: Work Style & "Personal User Manual" (25 min)       │
│ • Focus hours, feedback channels, stress markers            │
├─────────────────────────────────────────────────────────────┤
│ Block 3: Fresh-Eyes Audit & Early Unblocking (15 min)       │
│ • Confusing code, missing docs, tooling friction            │
└─────────────────────────────────────────────────────────────┘
```

### Block 1: Working Agreement & Safety (10 min)
- *"What was your experience with 1:1s in previous companies? What worked well, and what felt like a waste of time?"* (Uncovers past trauma with micromanagers or skipped meetings).
- *"Here is my fundamental rule: 1:1s belong to you. You set the agenda, and my role is to remove blockers and support your growth. How does that sound?"*
- *"Let's agree on our cancellation policy: this slot is protected. If either of us has an unavoidable emergency, we never cancel into thin air — we reschedule to a specific day within the same week."*

### Block 2: Work Style & "Personal User Manual" (25 min)
Every professional has a unique operating manual. Calibrating this on Day 1 saves months of friction:

- **Deep Work & Focus**: *"Do you need uninterrupted blocks of deep work (e.g. mornings without meetings) to be at your best?"*
- **Feedback Preferences**: *"How do you prefer to receive constructive feedback: immediately in private chat, written down before our 1:1 so you have time to digest it, or spoken face-to-face?"*
- **Stress Signals**: *"When you are under severe stress or feeling overwhelmed, how does that usually show from the outside (do you go quiet, become defensive, work 14 hours)? How would you like me to step in?"*
- **Recognition**: *"How do you prefer your achievements to be recognized: a public shout-out on team Slack/Demo, or a quiet private conversation acknowledging the engineering complexity?"*

### Block 3: Fresh-Eyes Audit (15 min)
New hires have a superpower that disappears after 30 days: **an unconditioned perspective.** They see confusing architecture, stale READMEs, and broken setups that veterans have learned to ignore.

- *"What part of our codebase, onboarding docs, or architecture felt the most confusing or illogical during your first two weeks?"*
- *"Do you have all the equipment, credentials, and context you need right now?"*
- *"Is there anyone across other teams you need an introduction to?"*

---

## 4. In-Platform Field Mapping (`onboarding`)

When you create an anketa with the `onboarding` template, `encrypted1on1` replaces routine status fields with onboarding-specific questions:

| Template Field | Who Fills It | Purpose |
| :--- | :--- | :--- |
| **Mood** | Employee | Universal check-in: establishes the habit of honest emotional signaling. |
| **Working Agreement** (`workingAgreement`) | Employee | Reflection on past meeting experiences and expectations for collaboration. |
| **Work Style** (`workStyle`) | Employee | Radios for feedback channel (`Chat`, `Written`, `Face-to-face`) plus deep work needs and stress signals. |
| **Fresh-Eyes Audit** (`freshEyesAudit`) | Employee | Dated list of early setup friction, documentation gaps, and confusing processes. |
| **Achievements & Discuss** | Employee | Space for early small wins and questions the newcomer wants to ask. |
| **Period Summary** (`periodSummary`) | Manager | High-level synthesis of how the initial onboarding phase is progressing. |
| **Feedback** (`feedback`) | Manager | Specific guidance, encouragement, and early course-corrections. |
| **Support / Unblocking** (`support`) | Manager | Commitments on removing hurdles and facilitating connections. |
| **Readiness Check** (`readinessCheck`) | Manager | Radio (`Yes`, `Partial`, `No`) confirming whether the newcomer has all necessary access, tooling, and context. |

---

## 5. Post-Meeting: The 24-Hour Quick Win

The single most impactful thing a manager can do after a first 1:1:
> **Identify one blocker the new hire mentioned (e.g. an ungranted access request, a broken build step, an intro to an architect) and solve it within 24 hours.**

This proves beyond any doubt that meetings with you are a tool with real operational leverage, not empty corporate talk.

### Archiving and Transitioning
When you archive this anketa, `encrypted1on1`'s lifecycle service automatically switches the pair's next meeting to the `regular` template. You are now ready for your regular bi-weekly rhythm!
