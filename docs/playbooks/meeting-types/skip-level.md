# Playbook: The Skip-Level 1:1 (Leadership & Strategy)

[← Back to Playbooks](../README.md)

> **Participants:** Senior Engineering Leader (Director / VP of Engineering / CTO) & Frontline Engineer (Senior / Staff / Lead)
> **Cadence:** Quarterly or Bi-annually (Every 3–6 months per engineer or rotation)
> **Duration:** 30–45 minutes
> **Format in encrypted1on1:** An ad-hoc anketa between the senior leader and the engineer.

---

## 1. Overview and Organizational Leverage

In growing engineering organizations, bad news and process friction are inevitably smoothed over and sanitized as they travel up the management chain. Middle managers unconsciously filter reports to signal that *"everything is under control."*

As a result, executive leadership often discovers architecture dead-ends, severe technical debt, or escalating attrition risks only when a critical release slips or production collapses.

### The Purpose of a Skip-Level Meeting:
- **Unfiltered Reality Check**: Directly understanding how tools, infrastructure, and policies feel on the frontlines.
- **Strategic Calibration**: Verifying whether executive vision translates into everyday engineering decisions, or if it feels like corporate slogans.
- **Systemic Problem Solving**: Removing institutional blockers and cross-team dependencies that exceed the authority of a single frontline team lead.

---

## 2. The Golden Rules of Skip-Level Etiquette

### Rule 1: Never Undermine the Frontline Manager
The single greatest hazard of a skip-level is turning it into a secret witch-hunt or performance review of the engineer's direct supervisor.
> **Position the meeting as an audit of the *environment, tools, and processes*, not an evaluation of personalities.**

### Rule 2: Always Align with the Team Lead First
Never schedule a skip-level behind a team lead's back. Send a quick note to the manager:
```text
"Hi Sarah! I'm scheduling regular quarterly skip-level chats with engineers across your team.
My goal is to hear their thoughts on our platform tools, architecture, and company strategy.
This is not an evaluation of your leadership. If there are any topics you think I should be
aware of before talking with them, please let me know!"
```

### Rule 3: Defuse Panic in the Calendar Invite
When an engineer receives a calendar invitation titled *"Sync with CTO"* or *"1:1 with VP"*, their instinctive reaction is sheer terror: *"Am I getting laid off?"* or *"Did I break something on production?"*

**Send an invitation that explicitly eliminates fear:**

```markdown
Subject: Quarterly Skip-Level Chat — Coffee & Strategy

Hi! This is our regular quarterly skip-level meeting.

To put your mind at ease immediately: this is NOT a performance review, NOT an audit of your tickets,
and you are NOT in trouble!

My goal is simply to listen: what is working well in our engineering stack, where are tools and
processes slowing you down, and how can leadership help your team build faster with less stress?

No preparation needed. Looking forward to our chat!
```

---

## 3. The 4-Block Agenda (35–45 Minutes)

```
┌─────────────────────────────────────────────────────────────┐
│ Block 1: Tension Relief & Framing (5–7 min)                 │
│ • Establish the senior leader as listener, not speaker      │
├─────────────────────────────────────────────────────────────┤
│ Block 2: Strategic Clarity & Product Context (12 min)       │
│ • Does high-level strategy make sense in the code?          │
├─────────────────────────────────────────────────────────────┤
│ Block 3: Systemic Bottlenecks & Tooling Friction (15 min)   │
│ • Cross-team dependencies, CI/CD, architecture debt         │
├─────────────────────────────────────────────────────────────┤
│ Block 4: Executive Commitments & Closing the Loop (8 min)   │
│ • Synthesizing findings, agreeing on 1 executive action     │
└─────────────────────────────────────────────────────────────┘
```

### Block 1: Tension Relief & Framing (5–7 min)
- *"Thank you for making time! Let me reiterate: my job today is to listen 80% of the time. We aren't checking tickets. How has your week been?"*
- *"What part of your engineering work right now brings you the most genuine satisfaction?"*

### Block 2: Strategic Clarity & Reality Check (12 min)
- *"How clear is our technical and company strategy to you right now? If a new joiner asked you why our current roadmap matters, how would you explain it?"*
- *"What do we talk about at Company All-Hands that feels completely disconnected from your day-to-day engineering reality?"*
- *"Do you understand how the services you are building create value for our paying customers, or does it feel like building in the dark?"*

### Block 3: Systemic Bottlenecks & Tooling Friction (15 min)
Frontline engineers know exactly where company money is being wasted:
- *"What in our infrastructure (CI/CD pipelines, staging environments, build times, test suites) steals the most time and sanity from your team?"*
- *"How is collaboration with partner departments (Platform, Security, Data, Product): are there walls or weeks of waiting for approvals?"*
- *"If you were CTO for one week with unlimited authority, what single process, tool, or policy would you abolish on Day 1?"*

### Block 4: Executive Commitments & Synthesis (8 min)
- *"Of everything we covered today, what is the single biggest institutional blocker standing in your way?"*
- *"Is there anything important I haven't asked you about that leadership needs to know?"*
- *"I am committing to investigating the CI/CD pipeline bottleneck with our Platform team, and I will report back to you within two weeks."*

---

## 4. Skip-Level Anti-Patterns

| Anti-Pattern | Why It Destroys Trust | Better Approach |
| :--- | :--- | :--- |
| **"So, how is your manager doing?"** | Puts the engineer in an impossible position of betraying their boss or lying. Creates paranoia. | Focus on processes and tools. If the engineer brings up manager friction, guide them: *"Have you shared this with them on your 1:1?"* |
| **Making operational promises over the lead's head** | If a VP overrides sprint priorities or reassigns features during a skip-level, the frontline manager's authority is dismantled. | Never assign tasks on the spot. Take notes, discuss the systemic issue with the lead, and let the lead guide team implementation. |
| **The Feedback Black Hole** | Listening to real grievances (slow staging, broken tooling) and doing nothing about them breeds cynicism. | Commit to solving only 1 systemic issue, but communicate progress transparently. |

---

## 5. Post-Meeting Protocol for Senior Leaders

1. **Spot Organizational Patterns (within 24 hours):** Compare notes across skip-levels. If 4 engineers from different teams report that Security approvals take 10 business days, that is an executive-level systemic failure, not team friction.
2. **Align with the Frontline Manager (within 48 hours):** Share systemic themes with the team lead without breaking private confidences or putting the engineer on the spot:
   > *"There is a strong desire in the team for faster integration test suites. Let's see how Platform engineering can allocate resources to assist you."*
3. **Close the Loop with the Engineer (within 7 days):** Send a brief private message updating them on the blocker they raised:
   > *"Hi Alex! Following up on our chat regarding staging delays: we just approved a dedicated DevOps sprint to overhaul our test runners. Thank you for flagging that!"*
4. **Archive & Prevent Auto-Recurrence in encrypted1on1:** When archiving the meeting, **check "Don't create the next meeting" (`skipNextMeeting`)**. Because senior leaders and frontline engineers do not normally share an open anketa, the platform treats a newly created meeting as the start of a recurring pair chain. Ticking this box ensures that an unwanted bi-weekly follow-up is not automatically scheduled.
