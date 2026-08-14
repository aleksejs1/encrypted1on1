import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { DEMO_LOCALES, CONTENT } from './demo-fixture-content.mjs';

/**
 * Regenerates backend/fixtures/demo-seed.json — the seed data behind
 * `bin/console app:reset-demo-data` and DEMO_MODE (see
 * private/demo-mode-plan.md, not tracked in git, and docs/deployment.md
 * for the operator-facing version). Run this again only if you want to
 * change the demo content/credentials; the reset command itself never
 * needs it and never runs a browser.
 *
 * One employee/manager pair per supported UI locale (see demo-fixture-
 * content.mjs), each with a real 3-cycle history (2 archived, 1 current) —
 * driven entirely through the real app UI/archive flow so goal
 * carry-forward and outcome carry-forward happen exactly as they would for
 * a real user, not simulated. The browser UI itself always stays in
 * English throughout generation (selectors/button text are stable that
 * way); only the *typed* content is locale-specific — see
 * demo-fixture-content.mjs's own docblock.
 *
 * Drives the REAL app UI in a real browser (Playwright), with real
 * X25519/argon2id/XChaCha20-Poly1305 crypto — not hand-replicated crypto in
 * Node. Every ciphertext blob and sealed key in the resulting fixture is
 * captured directly from the real backend via GET /api/anketas/bulk (once
 * per participant, after all 3 cycles exist) rather than stitching
 * together intermediate mutation responses — simpler and just as genuine,
 * since a sealed key/blob is always re-fetchable, not a one-shot capture.
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
const PASSWORD = 'e1o1-demo-2026';

const FEELING_LABELS = {
  excited: 'Excited',
  anxious: 'Anxious',
  confident: 'Confident',
  overwhelmed: 'Overwhelmed',
  motivated: 'Motivated',
  frustrated: 'Frustrated',
};

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

async function fillEmployeeSide(scope, c) {
  await checkRadio(scope, 'moodNow', c.moodNow);
  await checkRadio(scope, 'moodTrend', c.moodTrend);
  await fillTextarea(scope, 0, c.moodText);

  for (const feeling of c.feelings) {
    await scope
      .getByRole('button', { name: FEELING_LABELS[feeling], exact: true })
      .click();
  }
  await fillTextarea(scope, 1, c.feelingsText);

  await checkRadio(scope, 'workloadNow', c.workloadNow);
  await checkRadio(scope, 'workloadTrend', c.workloadTrend);
  await fillTextarea(scope, 2, c.workloadText);

  for (const entry of c.growth) await addListEntry(scope, 0, entry);
  await fillTextarea(scope, 3, c.harder);
  for (const entry of c.achievements) await addListEntry(scope, 1, entry);
  for (const entry of c.whatElse) await addListEntry(scope, 2, entry);
}

async function fillManagerSide(scope, c) {
  await fillTextarea(scope, 0, c.howWasPeriod);
  await fillTextarea(scope, 1, c.feedback);
  await fillTextarea(scope, 2, c.howCanIHelp);
  for (const entry of c.achievements) await addListEntry(scope, 0, entry);
  for (const entry of c.whatElse) await addListEntry(scope, 1, entry);
}

async function publish(page, anketaId, scope) {
  await Promise.all([
    page.waitForResponse(
      (res) =>
        res.request().method() === 'POST' &&
        res.url().endsWith(`/api/anketas/${anketaId}/publish`),
    ),
    scope.getByRole('button', { name: 'Publish' }).click(),
  ]);
  await page.getByText('Published').first().waitFor();
}

async function addComment(managerPage, anketaId, text) {
  const counterpartSide = managerPage.locator('.side-card').nth(1);
  const thread = counterpartSide.locator('.thread').first();
  await thread.getByRole('button', { name: /comment/i }).click();
  await thread.locator('input[type=text]').fill(text);
  await Promise.all([
    managerPage.waitForResponse(
      (res) =>
        res.request().method() === 'PUT' &&
        res.url().endsWith(`/api/anketas/${anketaId}/comments`),
    ),
    thread.getByRole('button', { name: 'Post' }).click(),
  ]);
}

async function addOutcome(page, anketaId, text) {
  await page.getByPlaceholder(/outcome/i).fill(text);
  await Promise.all([
    page.waitForResponse(
      (res) =>
        res.request().method() === 'PUT' &&
        res.url().endsWith(`/api/anketas/${anketaId}/outcomes`),
    ),
    page.getByRole('button', { name: 'Add', exact: true }).click(),
  ]);
}

async function markOutcomeDone(page, anketaId, text) {
  const item = page.locator('.outcome-item', { hasText: text });
  await Promise.all([
    page.waitForResponse(
      (res) =>
        res.request().method() === 'PUT' &&
        res.url().endsWith(`/api/anketas/${anketaId}/outcomes`),
    ),
    item.locator('.outcome-checkbox').check(),
  ]);
}

async function addGoal(page, anketaId, goal) {
  await page.getByPlaceholder(/goal title/i).fill(goal.title);
  await page
    .getByPlaceholder(/description/i)
    .first()
    .fill(goal.description);
  const targetDate = new Date();
  targetDate.setMonth(targetDate.getMonth() + 4);
  await page
    .locator('.add-goal-row input[type=date]')
    .fill(targetDate.toISOString().slice(0, 10));
  const [goalRes] = await Promise.all([
    page.waitForResponse(
      (res) =>
        res.request().method() === 'POST' &&
        res.url().endsWith(`/api/anketas/${anketaId}/goals`),
    ),
    page.getByRole('button', { name: 'Add goal', exact: false }).click(),
  ]);
  return JSON.parse(goalRes.request().postData()).goalUuid;
}

async function addCheckpoint(page, anketaId, checkpoint) {
  const goalCard = page.locator('.goal-card').first();
  await goalCard
    .locator('.checkpoint-form input[type=text]')
    .fill(checkpoint.text);
  await goalCard.locator('.checkpoint-form select').selectOption(checkpoint.tag);
  await Promise.all([
    page.waitForResponse(
      (res) =>
        res.request().method() === 'PUT' &&
        res.url().endsWith(`/api/anketas/${anketaId}/goal-checkpoints`),
    ),
    goalCard.getByRole('button', { name: /add checkpoint/i }).click(),
  ]);
}

async function archive(page, anketaId) {
  await Promise.all([
    page.waitForResponse(
      (res) =>
        res.request().method() === 'POST' &&
        res.url().endsWith(`/api/anketas/${anketaId}/archive`),
    ),
    page.getByRole('button', { name: 'Archive', exact: true }).click(),
  ]);
}

async function currentAnketaId(page, excludeIds) {
  const res = await page.request.get(`${BASE_URL}/api/anketas`);
  const list = await res.json();
  const match = list.find(
    (a) => a.archivedAt === null && !excludeIds.includes(a.id),
  );
  if (!match) throw new Error('Could not find the newly auto-created anketa.');
  return match.id;
}

async function runLocale(browser, localeCode) {
  const c = CONTENT[localeCode];
  console.log(`\n=== ${localeCode} ===`);

  const employeeToken = createActivationLink(c.employeeEmail);
  const managerToken = createActivationLink(c.managerEmail);

  const { page: employee, credentials: employeeCreds } =
    await activateAndCapture(browser, employeeToken);
  const { page: manager, credentials: managerCreds } =
    await activateAndCapture(browser, managerToken);
  console.log('Accounts activated.');

  // --- Cycle 1: create ---
  await employee.goto(`${BASE_URL}/anketas/new`);
  await employee
    .getByPlaceholder('Type an email to search…')
    .fill(c.managerEmail);
  await employee.getByRole('button', { name: c.managerEmail }).click();
  const meetingDate = new Date();
  meetingDate.setDate(meetingDate.getDate() + 5);
  await employee
    .locator('#meeting-date')
    .fill(meetingDate.toISOString().slice(0, 10));

  const [createRes] = await Promise.all([
    employee.waitForResponse(
      (res) =>
        res.request().method() === 'POST' &&
        res.url().endsWith('/api/anketas'),
    ),
    employee.getByRole('button', { name: 'Create anketa' }).click(),
  ]);
  const cycle1Id = (await createRes.json()).id;
  await employee.waitForURL(/\/anketas\/[0-9a-f-]+$/);
  console.log('Cycle 1 anketa created:', cycle1Id);

  await fillEmployeeSide(employee.locator('.side-card').first(), c.cycle1.employee);
  await publish(employee, cycle1Id, employee.locator('.side-card').first());

  await manager.goto(`${BASE_URL}/anketas/${cycle1Id}`);
  await manager.waitForLoadState('networkidle');
  await fillManagerSide(manager.locator('.side-card').first(), c.cycle1.manager);
  await publish(manager, cycle1Id, manager.locator('.side-card').first());

  await addComment(manager, cycle1Id, c.cycle1.comment);

  await employee.reload();
  await employee.waitForLoadState('networkidle');
  await addOutcome(employee, cycle1Id, c.cycle1.outcome);

  await employee.reload();
  await employee.waitForLoadState('networkidle');
  const goalUuid = await addGoal(employee, cycle1Id, c.goal);

  await employee.reload();
  await employee.waitForLoadState('networkidle');
  await addCheckpoint(employee, cycle1Id, c.cycle1.checkpoint);
  console.log('Cycle 1 content filled.');

  // --- Archive cycle 1 -> auto-creates cycle 2 (carries the in-progress goal + outcome) ---
  await employee.reload();
  await employee.waitForLoadState('networkidle');
  await archive(employee, cycle1Id);
  const cycle2Id = await currentAnketaId(employee, [cycle1Id]);
  console.log('Cycle 2 anketa created:', cycle2Id);

  // --- Cycle 2: fill ---
  await employee.goto(`${BASE_URL}/anketas/${cycle2Id}`);
  await employee.waitForLoadState('networkidle');
  await fillEmployeeSide(employee.locator('.side-card').first(), c.cycle2.employee);
  await publish(employee, cycle2Id, employee.locator('.side-card').first());

  await manager.goto(`${BASE_URL}/anketas/${cycle2Id}`);
  await manager.waitForLoadState('networkidle');
  await fillManagerSide(manager.locator('.side-card').first(), c.cycle2.manager);
  await publish(manager, cycle2Id, manager.locator('.side-card').first());

  await addComment(manager, cycle2Id, c.cycle2.comment);

  await employee.reload();
  await employee.waitForLoadState('networkidle');
  await markOutcomeDone(employee, cycle2Id, c.cycle1.outcome);

  await manager.reload();
  await manager.waitForLoadState('networkidle');
  await addOutcome(manager, cycle2Id, c.cycle2.outcomeNew);

  await employee.reload();
  await employee.waitForLoadState('networkidle');
  await addCheckpoint(employee, cycle2Id, c.cycle2.checkpoint);
  console.log('Cycle 2 content filled.');

  // --- Archive cycle 2 -> auto-creates cycle 3 (current, left empty) ---
  await employee.reload();
  await employee.waitForLoadState('networkidle');
  await archive(employee, cycle2Id);
  const cycle3Id = await currentAnketaId(employee, [cycle1Id, cycle2Id]);
  console.log('Cycle 3 (current) anketa created:', cycle3Id);

  // --- Capture final state of all 3 cycles via the bulk endpoint ---
  const employeeBulk = await (
    await employee.request.get(`${BASE_URL}/api/anketas/bulk`)
  ).json();
  const managerBulk = await (
    await manager.request.get(`${BASE_URL}/api/anketas/bulk`)
  ).json();

  const cycles = [cycle1Id, cycle2Id, cycle3Id].map((id) => {
    const fromEmployee = employeeBulk.find((a) => a.id === id);
    const fromManager = managerBulk.find((a) => a.id === id);
    return {
      archived: fromEmployee.archivedAt !== null,
      missed: fromEmployee.missed,
      employeeSealedKey: fromEmployee.mySealedKey,
      managerSealedKey: fromManager.mySealedKey,
      employeeBlob: fromEmployee.employeeBlob,
      managerBlob: fromEmployee.managerBlob,
      commentsBlob: fromEmployee.commentsBlob,
      commentsVersion: fromEmployee.commentsVersion,
      outcomesBlob: fromEmployee.outcomesBlob,
      outcomesVersion: fromEmployee.outcomesVersion,
      goalCheckpointsBlob: fromEmployee.goalCheckpointsBlob,
      goalCheckpointsVersion: fromEmployee.goalCheckpointsVersion,
    };
  });

  const result = {
    employee: {
      email: c.employeeEmail,
      id: employeeCreds.id,
      authHash: employeeCreds.authKey,
      publicKey: employeeCreds.publicKey,
      encryptedPrivateKey: employeeCreds.encryptedPrivateKey,
    },
    manager: {
      email: c.managerEmail,
      id: managerCreds.id,
      authHash: managerCreds.authKey,
      publicKey: managerCreds.publicKey,
      encryptedPrivateKey: managerCreds.encryptedPrivateKey,
    },
    goalUuid,
    goalTitle: c.goal.title,
    goalDescription: c.goal.description,
    goalTargetDateOffsetMonths: 4,
    periodicityDays: 30,
    cycles,
  };

  // Closed explicitly, not left open — accumulating browser contexts across
  // locales (each with active WASM crypto/network activity) measurably slowed
  // and eventually timed out the *next* locale's UI interactions when this
  // wasn't here, confirmed by isolating a single locale in a fresh process.
  await employee.context().close();
  await manager.context().close();

  return result;
}

const browser = await chromium.launch();
const locales = {};
for (const localeCode of DEMO_LOCALES) {
  locales[localeCode] = await runLocale(browser, localeCode);
  // Written after every locale, not just at the end — a later locale's
  // failure shouldn't lose already-completed ones from a full rerun (which
  // would also collide on now-already-registered emails).
  fs.writeFileSync(
    FIXTURE_PATH,
    JSON.stringify(
      { generatedAt: new Date().toISOString(), password: PASSWORD, locales },
      null,
      '\t',
    ) + '\n',
  );
  console.log(`Fixture updated with ${localeCode} (${Object.keys(locales).length}/${DEMO_LOCALES.length} locales so far).`);
}
await browser.close();

console.log('\nFixture complete:', FIXTURE_PATH);
