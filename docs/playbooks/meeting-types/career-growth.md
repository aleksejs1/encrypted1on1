# Playbook: Quarterly Career Growth & Trajectory Dialogue

[← Back to Playbooks](../README.md)

> **Template in encrypted1on1:** `career_growth` ("Карьерный рост")
> **Cadence:** Quarterly (Every 90 days or ~3–6 months)
> **Duration:** 45–60 minutes
> **Participants:** Manager & Direct Report (Middle to Staff+)
> **Recurrence Note:** Non-recurring template. Archiving automatically returns the pair's next meeting to the `regular` template.

---

## 1. Overview and Core Philosophy

In the rush of sprint deadlines and product releases, it is dangerously easy for an engineer to spend two years diligently shipping tickets only to realize they have made zero career progress. According to Gallup studies, the lack of growth opportunities and career stagnation is the **#1 reason top performers leave companies**.

A quarterly career conversation is a dedicated strategic session separated from everyday operational tasks.

### The Golden Rule: Decouple from Performance & Salary Reviews
The most common mistake organizations make is combining career growth discussions with annual performance evaluations or compensation reviews.

> **When money, bonuses, or job security are on the table, people put on armor.**
> They defend their past actions, minimize mistakes, and overstate achievements. Honest reflection on weaknesses, doubts, and aspirations is impossible in that state.

**Keep career conversations at least 3–4 weeks apart from compensation reviews.** Career dialogues look forward at growth; performance evaluations look backward at delivery.

---

## 2. Dual-Track Growth: IC vs. Management

Never assume that the only path up is becoming an engineering manager. Pushing brilliant technical contributors into people management against their will often destroys a great engineer and produces an unhappy, ineffective lead.

`encrypted1on1` explicitly models three trajectory options:
1. **IC Depth (Individual Contributor)**: Deep technical expertise, systems design, architecture, and reliability (Senior → Staff → Principal Engineer).
2. **People Leadership**: Team health, hiring, mentoring, organizational design, and coaching (Engineering Manager / Director).
3. **Undecided / Exploring**: Actively evaluating options and sampling small leadership experiments before committing.

---

## 3. The 4-Block Agenda (45–60 Minutes)

```
┌─────────────────────────────────────────────────────────────┐
│ Block 1: Energy Retrospective & Pride (12 min)              │
│ • What fueled motivation, what caused chronic depletion     │
├─────────────────────────────────────────────────────────────┤
│ Block 2: Trajectory & Role Archetypes (18 min)              │
│ • Vision of ideal week in 1.5–2 years, identifying skill gap│
├─────────────────────────────────────────────────────────────┤
│ Block 3: Stretch Projects & Manager Sponsorship (15 min)    │
│ • Real company initiative to build target competency        │
├─────────────────────────────────────────────────────────────┤
│ Block 4: 90-Day Individual Development Plan (15 min)        │
│ • 1 primary goal, first concrete milestones                 │
└─────────────────────────────────────────────────────────────┘
```

### Block 1: Energy Retrospective & Pride (12 min)
- *"Looking back at the past 6 months, which project, architectural launch, or technical problem filled you with the most genuine professional pride?"*
- *"What types of tasks felt like a soul-draining grind that you would love to automate, delegate, or eliminate?"*
- *"Where do you feel you made the biggest leap in technical maturity that might have gone unnoticed by others?"*

### Block 2: Trajectory & Role Archetypes (18 min)
- *"Picture your ideal work week 18–24 months from now: what kind of problems are you solving, and who are you collaborating with?"*
- *"Which track pulls you more right now: Staff+ technical architecture or engineering management?"*
- *"What is the single biggest competency gap (e.g. driving consensus across teams, managing ambiguity, system design at scale) holding you back from that next level?"*

### Block 3: Stretch Projects & Manager Sponsorship (15 min)
A manager's real leverage is **sponsorship, not just advice**:
- **Mentorship** tells someone *how* to grow.
- **Sponsorship** *opens doors* and puts reputation on the line.

- *"In our upcoming roadmap, where can we assign you a stretch project that exercises this exact skill?"*
- *"What backing do you need from me: air cover to make early mistakes, introducing you to the principal architecture group, or protecting your calendar from interrupts?"*

### Block 4: 90-Day Development Plan (IDP) (15 min)
Avoid 10-point wish lists. Pick **one single focus area** for the next quarter:
- *"What is the ONE core competency goal we will commit to for the next 90 days?"*
- *"What are the first 1–2 tangible actions you will take in the next two weeks to kickstart this?"*

---

## 4. In-Platform Field Mapping (`career_growth`)

| Template Field | Who Fills It | Best Practice |
| :--- | :--- | :--- |
| **Mood** | Employee | Universal check-in. |
| **Energy Retrospective** (`energyRetrospective`) | Employee | Name specific energizing work (`energizingWork`) and draining tasks (`drainingWork`). |
| **Trajectory** (`trajectory`) | Employee | Select direction (`ic_depth`, `people_leadership`, `undecided`) and articulate the capability gap (`capabilityGap`). |
| **Development Plan** (`developmentPlan`) | Employee | One primary 90-day goal (`developmentGoal`) and step-by-step milestones (`developmentSteps`). |
| **Feedback** | Manager | Actionable coaching tailored to the employee's career ambitions. |
| **Sponsorship Offer** (`sponsorshipOffer`) | Manager | Define the stretch assignment (`stretchAssignment`) and manager backing commitments (`managerBacking`). |
| **Discuss** | Both | Open agenda items. |

---

## 5. Post-Meeting: Translating into Tracked Goals

1. **Add a Goal in encrypted1on1**:
   - Create a new entry under the **Goals** section representing the 90-day IDP goal.
   - Set a target date 90 days out.
   - Use **Checkpoints** on subsequent regular 1:1s to check in on progress (every 2–4 weeks).
2. **Manager Sponsorship Action within 7 Days**:
   - Introduce the employee to the relevant project group, advocate for them to lead an RFC, or approve educational budget.
3. **Archive**:
   - Archiving the anketa cleanly transitions the pair back to their regular meeting cycle while preserving the goal across cycles.
