# Handling Difficult Situations: Scripts for High-Stakes 1:1s

[← Back to Playbooks](README.md)

> **A field guide for managers and team members on navigating tension, emotional distress, critical feedback, and tough conversations without breaking trust.**

---

## The Reality of 1:1s

When everything is going well, running a 1:1 is easy. The true test of managerial skill and interpersonal maturity occurs when conversations get messy: an engineer shuts down, someone breaks into tears, critical feedback must be delivered, or a demand for a promotion lands on the table.

This guide provides battle-tested mindsets, what *not* to do, and verbatim scripts for the six most intimidating scenarios.

---

## Scenario 1: The "I'm Fine" Stonewall

**Situation:** The team member gives terse, one-word answers (*"Fine"*, *"Good"*, *"No blockers"*). Meanwhile, their pull requests are stalled, deadlines are slipping, and their energy is visibly low.

### The Underlying Psychology
The employee does not feel safe. They might fear that admitting a struggle will be used against them in an evaluation, or they have previous negative experiences where vulnerability was punished.

### ❌ What Novice Managers Do
- Accept the answer at face value: *"Great, let's wrap up 20 minutes early!"* (The problem festers until an emergency explosion).
- Press aggressively: *"You say you're fine, but your PR has been open for a week. What's taking so long?"* (Triggers immediate defensiveness and alienation).

### ✅ The Battle-Tested Script
```text
Manager: "I hear you saying things are fine, but I've noticed a pattern over the past couple
of weeks: our daily standups feel more subdued, and that Auth service PR seems unusually heavy.

I'm not bringing this up to evaluate you or push you. My job is to protect your momentum.
When things get quiet like this, it usually means there is a hidden blocker, a frustrating
dependency, or you are carrying too much on your plate alone.

What is one thing that has been more exhausting than usual this sprint?"
```
*(Then stay completely silent for 7 seconds. Let them process and fill the void).*

### In-App Execution in encrypted1on1
- Do not force them to type in the anketa if they are tense.
- Use your **Private Notes** to document observations and hypothesis.
- If they reveal a blocker, log a shared commitment under **Meeting Outcomes** where *you* take action to unblock them.

---

## Scenario 2: Delivering Critical Performance Feedback

**Situation:** A team member's code quality has dropped, they are missing commitments, or their communication in code reviews has become abrasive.

### The Underlying Psychology
Nobody comes to work wanting to do a bad job. Poor performance is almost always an output of:
1. Ambiguous expectations (they don't realize their standard is slipping).
2. Cognitive overload / personal crisis.
3. Lack of skills / missing feedback.

### ❌ What Novice Managers Do
- Sugarcoat with the "Praise Sandwich" (Compliment → Vague Criticism → Compliment). The employee leaves thinking they are doing great, while the manager thinks they delivered tough feedback.
- Attack character: *"You are being sloppy with your tests."*

### ✅ The Battle-Tested Script (The SBI Model)
```text
Manager: "I want to share some direct feedback with you about how code reviews went this week,
specifically on the Payment Gateway refactoring.

On Tuesday, when Jordan asked about the fallback logic, your response was: 'Read the docs,
I don't have time to explain basics.'

When you answer like that, the impact is that Jordan feels intimidated to ask necessary
architecture questions, review cycles slow down, and it creates tension in our team culture.

I know how much you care about shipping quickly, but maintaining a collaborative review
environment is just as critical as technical velocity. What was going on for you in that moment?"
```

### Next Steps:
1. **Listen to their context**: Did they feel rushed? Were they under deadline pressure?
2. **Agree on a behavior change**: *"Moving forward, if you feel short on time, let's have you ask for a quick 5-minute huddle instead of terse text comments. Can we agree on that?"*
3. **Capture it under Outcomes**: *"Commitment: Pair with Jordan on Payment review by Thursday."*

---

## Scenario 3: Emotional Distress & Tears

**Situation:** The direct report becomes overwhelmed, voice trembles, and they burst into tears during the call.

### The Underlying Psychology
They feel utterly exposed and embarrassed. They are mortified that their professional composure slipped in front of their manager.

### ❌ What Novice Managers Do
- Panic and try to fix it instantly: *"Don't cry! It's okay, everything is totally fine, don't worry!"* (Invalidates their feelings).
- Pretend it isn't happening and plow through the technical agenda.
- Immediately pry into deeply personal private matters: *"Is it your marriage?"*

### ✅ The Battle-Tested Script
```text
Manager (Calm, slow, gentle tone):
"Hey, take your time. There is absolutely no rush, and you have nothing to apologize for.
We work with complex, stressful systems, and we are all human beings first.

Would you like to take a 5-minute break, grab a glass of water, and come back?
Or would you prefer to reschedule the rest of our chat to tomorrow?
Whatever you need right now is completely okay."
```

### Crucial Follow-Through:
- If they want to continue, focus **only on listening and support**, not problem-solving.
- Switch the anketa to the `support_checkin` template if workload or burnout is the trigger.
- **Never record the call.** Use encrypted Private Notes only for support actions you promised.

---

## Scenario 4: The Immediate Raise / Promotion Demand

**Situation:** The employee opens the 1:1 with: *"I've been here for a year and doing senior work. I need a 25% salary bump and a Senior title next month, or I'll have to look elsewhere."*

### The Underlying Psychology
The employee feels undervalued and is using an ultimatum as a blunt instrument. They are anxious about market rates or feeling stuck.

### ❌ What Novice Managers Do
- Make immediate reckless promises they cannot keep: *"I'll make sure you get it next month!"* (When HR or budget vetoes it, trust is permanently obliterated).
- React with defensive anger: *"How dare you threaten me with an ultimatum?"*
- Shut down the discussion: *"Salary is handled by HR once a year, don't ask me."*

### ✅ The Battle-Tested Script
```text
Manager: "Thank you for being direct with me about where your head is at. I really value
your ambition, and I want to make sure you feel fairly rewarded and recognized for your impact here.

I cannot give you an instant 'yes' today on title or budget, because promotions and salary
bands involve our engineering leveling rubric and executive calibration.

What I CAN promise you is full transparency. Let's do two things:
1. First, let's open our Senior Engineer competency rubric together. Let's map out exactly
   where your contributions are hitting Senior level, and where the specific gaps are.
2. Second, I will take our concrete gap analysis to our Director and HR to understand
   the timeline and budget windows for off-cycle adjustments.

Fair enough?"
```

### In-App Execution in encrypted1on1:
- Open the `career_growth` template or create a **Goal** titled *"Path to Senior Engineer"* with clear milestones.
- Keep the actual salary negotiation separated from regular 1:1 check-ins.

---

## Scenario 5: Accusations of Micromanagement

**Situation:** The employee says: *"I feel like you don't trust me. You are asking for updates on Slack every day and checking all my PRs."*

### The Underlying Psychology
The employee's autonomy is feeling suffocated. Alternatively, there is an unstated mismatch in expectations: the manager is asking for updates because the employee is not communicating progress proactively.

### ❌ What Novice Managers Do
- Defend themselves: *"I'm not micromanaging, I'm just doing my job! You weren't updating Jira!"*
- Overcorrect by completely disengaging and ignoring the employee (leading to missed deliverables).

### ✅ The Battle-Tested Script
```text
Manager: "Thank you for telling me that directly. It takes courage to say that to your lead,
and I really appreciate the candor. If my behavior is feeling like micromanagement, that means
we have a breakdown in how we communicate, and I want to fix it.

My intention was never to breathe down your neck. The reason I was checking in frequently
was that our stakeholders were asking for release estimates, and I didn't have enough
visibility to shield you.

Let's calibrate: What would the ideal balance of autonomy and visibility look like for you?
How can we agree on an update cadence that gives me the context I need to protect you,
without making you feel policed?"
```

---

## Scenario 6: Cynicism About Company Strategy

**Situation:** The employee vents: *"This new platform pivot is ridiculous. Leadership has no idea what they're doing, and we're just wasting time rewriting working code."*

### The Underlying Psychology
The employee feels powerless and disconnected from the business rationale. Cynicism is often a protective armor worn by passionate engineers whose expectations were bruised.

### ❌ What Novice Managers Do
- Join in the cynical bashing of upper management: *"Yeah, executives are idiots, but what can we do."* (Destroys organizational alignment and fuels a toxic team spiral).
- Enforce blind obedience: *"That's the company strategy, stop complaining and do your job."* (Kills morale and engagement).

### ✅ The Battle-Tested Script
```text
Manager: "I hear your frustration, and I understand why it feels jarring from an engineering
standpoint — especially after all the hard work we put into the previous architecture.

When strategy pivots happen, the business context rarely gets explained clearly down to the code.
Here is what is driving this decision at the customer and market level... [explain the why].

You don't have to love every business constraint, but I need us to be aligned on execution.
From an architectural perspective, what are the biggest risks you see in this pivot,
and how can we design our migration so we don't repeat past mistakes?"
```
