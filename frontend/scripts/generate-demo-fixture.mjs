import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Regenerates backend/fixtures/demo-seed.json — the seed data behind
 * `bin/console app:reset-demo-data` and DEMO_MODE (see
 * private/demo-mode-plan.md, not tracked in git, and docs/deployment.md
 * for the operator-facing version). Run this again only if you want to
 * change the demo content/credentials; the reset command itself never
 * needs it and never runs a browser.
 *
 * Drives the REAL app UI in a real browser (Playwright), with real
 * X25519/argon2id/XChaCha20-Poly1305 crypto — not hand-replicated crypto in
 * Node. Every ciphertext blob and sealed key in the resulting fixture is
 * captured directly from the real network requests the app itself sent
 * (via page.waitForResponse(), paired explicitly with the action that
 * triggers it — a plain page.on('response') listener races against
 * page.waitForURL()/script exit and silently drops the capture, confirmed
 * the hard way), so the fixture is provably correct by construction rather
 * than by a second, separately-maintained implementation of the same
 * crypto model.
 *
 * Usage: run against a running dev stack (`make up`), from anywhere:
 *   node frontend/scripts/generate-demo-fixture.mjs
 */
const REPO_ROOT = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../..',
);
const FIXTURE_PATH = path.join(REPO_ROOT, 'backend/fixtures/demo-seed.json');

const BASE_URL = 'http://localhost:5173';
const EMPLOYEE_EMAIL = 'demo-employee@example.com';
const MANAGER_EMAIL = 'demo-manager@example.com';
const PASSWORD = 'e1o1-demo-2026';

function createActivationLink(email) {
  const output = execFileSync(
    'docker',
    [
      'compose',
      '-f',
      'docker-compose.dev.yml',
      'exec',
      '-T',
      'backend',
      'php',
      'bin/console',
      'app:create-activation-link',
      email,
      '--no-ansi',
    ],
    { encoding: 'utf-8', cwd: REPO_ROOT },
  );
  const match = output.match(/\/activate\/([a-f0-9]{64})/);
  if (!match) throw new Error('No token found:\n' + output);
  return match[1];
}

async function activateAndCapture(browser, token) {
  const context = await browser.newContext({
    viewport: { width: 1280, height: 900 },
  });
  const page = await context.newPage();
  await page.goto(`${BASE_URL}/activate/${token}`);
  await page.locator('#act-password').fill(PASSWORD);
  await page.locator('#act-confirm').fill(PASSWORD);

  const [response] = await Promise.all([
    page.waitForResponse(
      (res) =>
        res.request().method() === 'POST' &&
        /\/activation-tokens\/.+\/complete$/.test(res.url()),
    ),
    page.getByRole('button', { name: 'Activate' }).click(),
  ]);
  const reqBody = JSON.parse(response.request().postData());
  const resBody = await response.json();
  await page.waitForURL(BASE_URL + '/');

  return { page, credentials: { ...reqBody, id: resBody.id } };
}

async function checkRadio(scope, name, value) {
  await scope
    .locator(`label.radio:has(input[name="${name}"][value="${value}"])`)
    .click();
}
async function fillTextarea(scope, index, text) {
  await scope.locator('textarea').nth(index).fill(text);
}
async function addListEntry(scope, index, text) {
  const addRow = scope.locator('.add-entry').nth(index);
  await addRow.locator('input[type=text]').fill(text);
  await addRow.getByRole('button', { name: 'Add' }).click();
}

console.log('Provisioning demo accounts...');
const employeeToken = createActivationLink(EMPLOYEE_EMAIL);
const managerToken = createActivationLink(MANAGER_EMAIL);

const browser = await chromium.launch();
const { page: employee, credentials: employeeCreds } = await activateAndCapture(
  browser,
  employeeToken,
);
const { page: manager, credentials: managerCreds } = await activateAndCapture(
  browser,
  managerToken,
);
console.log('Both demo accounts activated with real crypto.');

// --- Create the anketa (employee creates, manager is the counterpart) ---
await employee.goto(`${BASE_URL}/anketas/new`);
await employee.getByPlaceholder('Type an email to search…').fill(MANAGER_EMAIL);
await employee.getByRole('button', { name: MANAGER_EMAIL }).click();
const meetingDate = new Date();
meetingDate.setDate(meetingDate.getDate() + 5);
await employee
  .locator('#meeting-date')
  .fill(meetingDate.toISOString().slice(0, 10));

const [createRes] = await Promise.all([
  employee.waitForResponse(
    (res) =>
      res.request().method() === 'POST' && res.url().endsWith('/api/anketas'),
  ),
  employee.getByRole('button', { name: 'Create anketa' }).click(),
]);
const createBody = JSON.parse(createRes.request().postData());
const anketaId = (await createRes.json()).id;
await employee.waitForURL(/\/anketas\/[0-9a-f-]+$/);
console.log('Anketa created:', anketaId);

// --- Employee side: full realistic content (private/demo-anketa-data.md) ---
const empSide = employee.locator('.side-card').first();

await checkRadio(empSide, 'moodNow', 'good');
await checkRadio(empSide, 'moodTrend', 'better');
await fillTextarea(
  empSide,
  0,
  "Shipped the billing export migration this period, which had been hanging over me for a while — feeling a lot lighter now that it's out.",
);

await empSide.getByRole('button', { name: 'Motivated', exact: true }).click();
await empSide.getByRole('button', { name: 'Confident', exact: true }).click();
await fillTextarea(
  empSide,
  1,
  'Onboarding the new hire went well — good reminder that I actually enjoy the mentoring side of things.',
);

await checkRadio(empSide, 'workloadNow', 'just_right');
await checkRadio(empSide, 'workloadTrend', 'less');
await fillTextarea(
  empSide,
  2,
  "Workload dropped a bit now that the migration's done. Good time to pick up something new if there's a fit.",
);

await addListEntry(
  empSide,
  0,
  'Learned that short recorded walkthroughs get way more engagement than written code-review comments — switching to that for the trickier reviews.',
);
await addListEntry(
  empSide,
  0,
  'Sat in on a postmortem for the first time — useful to see how the team traces an incident back to root cause.',
);

await fillTextarea(
  empSide,
  3,
  'Cross-team dependency requests still go through a lot of back-and-forth over Slack before anyone commits to a timeline. A shared intake process would save a lot of chasing.',
);

await addListEntry(
  empSide,
  1,
  'Shipped the billing export migration end to end, ahead of the original estimate.',
);
await addListEntry(
  empSide,
  1,
  'Mentored the new hire through their first on-call rotation without a single escalation.',
);

await addListEntry(
  empSide,
  2,
  'Interested in leading a cross-team project next quarter — want to talk about what that path looks like.',
);

const [employeePublishRes] = await Promise.all([
  employee.waitForResponse(
    (res) =>
      res.request().method() === 'POST' &&
      res.url().endsWith(`/api/anketas/${anketaId}/publish`),
  ),
  empSide.getByRole('button', { name: 'Publish' }).click(),
]);
const employeeBlob = JSON.parse(employeePublishRes.request().postData()).blob;
await employee.getByText('Published').first().waitFor();
console.log('Employee side published.');

// --- Manager side ---
await manager.goto(`${BASE_URL}/anketas/${anketaId}`);
await manager.waitForLoadState('networkidle');
const mgrSide = manager.locator('.side-card').first();

await fillTextarea(
  mgrSide,
  0,
  'Strong period — Priya owned the billing export migration from design through rollout and it landed cleanly. Also stepped up on mentoring without being asked to.',
);
await fillTextarea(
  mgrSide,
  1,
  "Ownership and follow-through are excellent — I don't worry about migration work once it's assigned to Priya. Would love to see more proactive updates in standup rather than waiting to be asked; the work itself is rarely the issue, it's visibility.",
);
await fillTextarea(
  mgrSide,
  2,
  "I can help unblock the cross-team dependency process Priya raised — I'll bring it up with the platform team lead this week.",
);
await addListEntry(
  mgrSide,
  0,
  'Owned the billing export migration from design to ship with zero rollback.',
);
await addListEntry(
  mgrSide,
  0,
  "First-time mentor for a new hire's on-call rotation — smooth ramp-up, no incidents.",
);
await addListEntry(
  mgrSide,
  1,
  'Ready to talk through what leading a cross-team project would actually look like for Priya next quarter.',
);

const [managerPublishRes] = await Promise.all([
  manager.waitForResponse(
    (res) =>
      res.request().method() === 'POST' &&
      res.url().endsWith(`/api/anketas/${anketaId}/publish`),
  ),
  mgrSide.getByRole('button', { name: 'Publish' }).click(),
]);
const managerBlob = JSON.parse(managerPublishRes.request().postData()).blob;
await manager.getByText('Published').first().waitFor();
console.log('Manager side published.');

// --- One comment, from the manager on the employee's mood field ---
const mgrCounterpartSide = manager.locator('.side-card').nth(1);
const firstThread = mgrCounterpartSide.locator('.thread').first();
await firstThread.getByRole('button', { name: /comment/i }).click();
await firstThread
  .locator('input[type=text]')
  .fill('Congrats on shipping this ahead of schedule!');
const [commentsRes] = await Promise.all([
  manager.waitForResponse(
    (res) =>
      res.request().method() === 'PUT' &&
      res.url().endsWith(`/api/anketas/${anketaId}/comments`),
  ),
  firstThread.getByRole('button', { name: 'Post' }).click(),
]);
const commentsBlob = JSON.parse(commentsRes.request().postData()).blob;
const commentsVersion = (await commentsRes.json()).commentsVersion;
console.log('Comment posted, version', commentsVersion);

// --- Meeting outcomes: one from each side ---
await employee.reload();
await employee.waitForLoadState('networkidle');
await employee
  .getByPlaceholder(/outcome/i)
  .fill(
    'Priya to draft a one-page proposal for the cross-team project by next meeting.',
  );
const [outcomesRes1] = await Promise.all([
  employee.waitForResponse(
    (res) =>
      res.request().method() === 'PUT' &&
      res.url().endsWith(`/api/anketas/${anketaId}/outcomes`),
  ),
  employee.getByRole('button', { name: 'Add', exact: true }).click(),
]);
console.log(
  'Employee outcome added, version',
  (await outcomesRes1.json()).outcomesVersion,
);

await manager.reload();
await manager.waitForLoadState('networkidle');
await manager
  .getByPlaceholder(/outcome/i)
  .fill(
    'Jordan to raise the dependency-intake process with the platform team lead.',
  );
const [outcomesRes2] = await Promise.all([
  manager.waitForResponse(
    (res) =>
      res.request().method() === 'PUT' &&
      res.url().endsWith(`/api/anketas/${anketaId}/outcomes`),
  ),
  manager.getByRole('button', { name: 'Add', exact: true }).click(),
]);
const outcomesBlob = JSON.parse(outcomesRes2.request().postData()).blob;
const outcomesVersion = (await outcomesRes2.json()).outcomesVersion;
console.log('Manager outcome added, version', outcomesVersion);

// --- One goal (employee-authored, plaintext title/description) ---
await employee.reload();
await employee.waitForLoadState('networkidle');
await employee
  .getByPlaceholder(/goal title/i)
  .fill('Lead one cross-team project end to end');
await employee
  .getByPlaceholder(/description/i)
  .first()
  .fill('Own scoping and delivery of a project spanning at least two teams.');
const targetDate = new Date();
targetDate.setMonth(targetDate.getMonth() + 3);
await employee
  .locator('.add-goal-row input[type=date]')
  .fill(targetDate.toISOString().slice(0, 10));
const [goalRes] = await Promise.all([
  employee.waitForResponse(
    (res) =>
      res.request().method() === 'POST' &&
      res.url().endsWith(`/api/anketas/${anketaId}/goals`),
  ),
  employee.getByRole('button', { name: 'Add goal', exact: false }).click(),
]);
const goalCreateBody = JSON.parse(goalRes.request().postData());
const goalId = (await goalRes.json()).id;
console.log('Goal created:', goalId);

// --- One checkpoint on that goal ---
await employee.reload();
await employee.waitForLoadState('networkidle');
const goalCard = employee.locator('.goal-card').first();
await goalCard
  .locator('.checkpoint-form input[type=text]')
  .fill('Kicked off scoping conversations with the platform team.');
await goalCard.locator('.checkpoint-form select').selectOption('on_track');
const [checkpointsRes] = await Promise.all([
  employee.waitForResponse(
    (res) =>
      res.request().method() === 'PUT' &&
      res.url().endsWith(`/api/anketas/${anketaId}/goal-checkpoints`),
  ),
  goalCard.getByRole('button', { name: /add checkpoint/i }).click(),
]);
const checkpointsBlob = JSON.parse(checkpointsRes.request().postData()).blob;
const checkpointsVersion = (await checkpointsRes.json()).goalCheckpointsVersion;
console.log('Checkpoint added, version', checkpointsVersion);

await browser.close();

// --- Assemble the fixture ---
const fixture = {
  generatedAt: new Date().toISOString(),
  password: PASSWORD,
  employee: {
    email: EMPLOYEE_EMAIL,
    id: employeeCreds.id,
    authHash: employeeCreds.authKey,
    publicKey: employeeCreds.publicKey,
    encryptedPrivateKey: employeeCreds.encryptedPrivateKey,
  },
  manager: {
    email: MANAGER_EMAIL,
    id: managerCreds.id,
    authHash: managerCreds.authKey,
    publicKey: managerCreds.publicKey,
    encryptedPrivateKey: managerCreds.encryptedPrivateKey,
  },
  anketa: {
    employeeSealedKey: createBody.mySealedKey,
    managerSealedKey: createBody.counterpartSealedKey,
    periodicityDays: createBody.periodicityDays ?? 30,
    meetingDateOffsetDays: 5,
    employeeBlob,
    managerBlob,
    commentsBlob,
    commentsVersion,
    outcomesBlob,
    outcomesVersion,
    goalCheckpointsBlob: checkpointsBlob,
    goalCheckpointsVersion: checkpointsVersion,
  },
  goal: {
    // Client-generated (crypto.randomUUID() in Anketa.svelte's
    // handleAddGoal) — captured from the real request body, not assumed.
    goalUuid: goalCreateBody.goalUuid,
    title: goalCreateBody.title,
    description: goalCreateBody.description,
    targetDateOffsetMonths: 3,
  },
};

fs.writeFileSync(FIXTURE_PATH, JSON.stringify(fixture, null, '\t') + '\n');
console.log('Fixture written to', FIXTURE_PATH);
