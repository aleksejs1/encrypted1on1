import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Regenerates every screenshot under docs/screenshots/, plus the two hero
 * preview images the e1o1-landing repo's homepage uses (only if that repo
 * is checked out as a sibling directory of this one — skipped otherwise,
 * see the check near the bottom) — against a real running dev stack, with
 * real content and real crypto, never mocked or hand-drawn. Content is the
 * same "Priya Natarajan / Jordan Lee" narrative as
 * private/demo-anketa-data.md (not tracked in git), reused here verbatim
 * so anyone re-running this after a UI change gets a like-for-like
 * comparison against the previous set. Fixed john.doe@example.com /
 * jane.doe@example.com accounts — distinct from the demo-mode fixture
 * (backend/fixtures/demo-seed.json), deliberately: these are throwaway
 * local-dev accounts, reset and regenerated fresh on every run, not the
 * shared production demo.
 *
 * Rerun this whenever the UI changes enough that the committed
 * screenshots would mislead a reader (a new design pass, a new feature
 * visible on one of these screens, a font/token change) — not on every
 * commit.
 *
 * Usage: run against a running dev stack (`make up`), from anywhere:
 *   node frontend/scripts/generate-doc-screenshots.mjs
 *
 * Does NOT attempt encryption.png (the annotated devtools screenshot) —
 * that one needs a real, non-headless browser with DevTools panel open
 * and hand-drawn circles, which isn't practical to script. Redo that one
 * by hand if the API response shape changed.
 */
const REPO_ROOT = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../..',
);
const OUT_DIR = path.join(REPO_ROOT, 'docs/screenshots');
const BASE_URL = 'http://localhost:5173';
const PASSWORD = 'doc-screenshot-2026';
const EMPLOYEE_EMAIL = 'john.doe@example.com';
const MANAGER_EMAIL = 'jane.doe@example.com';
const VIEWPORT = { width: 1400, height: 900 };

// The demo-mode fixture's own fixed credentials (frontend/src/demo.ts,
// backend/fixtures/demo-seed.json) — a real employee/manager pair per
// supported locale, each with genuinely translated content (not English
// text under a translated UI, which `?lang=` alone would produce). Used
// below for the anketa_ru/lv/es.png screenshots specifically.
const DEMO_PASSWORD = 'e1o1-demo-2026';
const DEMO_LOCALES = ['ru', 'lv', 'es'];

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

/** Direct DB write, bypassing the UI entirely — see the meetingDate comment further down for why. */
function backdateMeetingDate(anketaId, daysAgo) {
  const past = new Date();
  past.setDate(past.getDate() - daysAgo);
  const sqlDate = past.toISOString().slice(0, 19).replace('T', ' ');
  execFileSync(
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
      'dbal:run-sql',
      `UPDATE anketas SET meetingDate = '${sqlDate}' WHERE id = '${anketaId}'`,
    ],
    { encoding: 'utf-8', cwd: REPO_ROOT },
  );
}

async function activate(browser, token) {
  const context = await browser.newContext({ viewport: VIEWPORT });
  const page = await context.newPage();
  await page.goto(`${BASE_URL}/activate/${token}`);
  await page.locator('#act-password').fill(PASSWORD);
  await page.locator('#act-confirm').fill(PASSWORD);
  await Promise.all([
    page.waitForResponse(
      (res) =>
        res.request().method() === 'POST' &&
        /\/activation-tokens\/.+\/complete$/.test(res.url()),
    ),
    page.getByRole('button', { name: 'Activate' }).click(),
  ]);
  await page.waitForURL(BASE_URL + '/');
  return page;
}

/** For an already-existing account (the demo-mode fixture's), not a fresh activation. */
async function login(browser, email, password, lang) {
  const context = await browser.newContext({ viewport: VIEWPORT });
  const page = await context.newPage();
  await page.goto(`${BASE_URL}/?lang=${lang}`);
  await page.waitForLoadState('networkidle');
  await page.locator('#login-email').fill(email);
  await page.locator('#login-password').fill(password);
  await Promise.all([
    page.waitForResponse(
      (res) =>
        res.request().method() === 'POST' && res.url().endsWith('/api/login'),
    ),
    page.locator('form button[type=submit]').click(),
  ]);
  await page.locator('#login-email').waitFor({ state: 'detached' });
  await page.waitForLoadState('networkidle');
  return page;
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

async function shot(page, filename, { fullPage = true } = {}) {
  await page.waitForTimeout(300);
  await page.screenshot({ path: path.join(OUT_DIR, filename), fullPage });
  console.log('Wrote', filename);
}

/** Lets a rerun start clean without requiring the previous run's accounts to be removed by hand first. */
function resetAccounts() {
  const sqlStatements = [
    `DELETE FROM goals WHERE anketa_id IN (SELECT id FROM anketas WHERE employee_id IN (SELECT id FROM users WHERE email IN ('${EMPLOYEE_EMAIL}', '${MANAGER_EMAIL}')) OR manager_id IN (SELECT id FROM users WHERE email IN ('${EMPLOYEE_EMAIL}', '${MANAGER_EMAIL}')))`,
    `DELETE FROM anketas WHERE employee_id IN (SELECT id FROM users WHERE email IN ('${EMPLOYEE_EMAIL}', '${MANAGER_EMAIL}')) OR manager_id IN (SELECT id FROM users WHERE email IN ('${EMPLOYEE_EMAIL}', '${MANAGER_EMAIL}'))`,
    `DELETE FROM users WHERE email IN ('${EMPLOYEE_EMAIL}', '${MANAGER_EMAIL}')`,
  ];
  for (const sql of sqlStatements) {
    execFileSync(
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
        'dbal:run-sql',
        sql,
      ],
      { encoding: 'utf-8', cwd: REPO_ROOT },
    );
  }
}

/** Restores every locale's demo-mode fixture pair to its known-good seeded state — see ResetDemoDataCommand. */
function resetDemoModeData() {
  execFileSync(
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
      'app:reset-demo-data',
    ],
    { encoding: 'utf-8', cwd: REPO_ROOT },
  );
}

// --- e1o1-landing's hero preview crop, one per supported locale, light + dark ---
const LANDING_SCREENSHOTS_DIR = path.join(
  REPO_ROOT,
  '../e1o1-landing/static/screenshots',
);
const LANDING_REPO_PRESENT = fs.existsSync(LANDING_SCREENSHOTS_DIR);
if (!LANDING_REPO_PRESENT) {
  console.log(
    `Skipping e1o1-landing hero previews — ${LANDING_SCREENSHOTS_DIR} not found (that repo isn't checked out as a sibling of this one).`,
  );
}

/** Crops the current page's own first .side-card to the hero's fixed 688x560, light + dark, for one locale. */
async function captureHeroCrop(page, locale) {
  if (!LANDING_REPO_PRESENT) return;
  await page.waitForTimeout(300);
  const cardBox = await page.locator('.side-card').first().boundingBox();
  const clip = { x: cardBox.x, y: cardBox.y, width: 688, height: 560 };
  await page.screenshot({
    path: path.join(
      LANDING_SCREENSHOTS_DIR,
      `anketa-preview-${locale}-light.png`,
    ),
    fullPage: true,
    clip,
  });
  await page.locator('.btn-icon').first().click();
  await page.waitForTimeout(300);
  await page.screenshot({
    path: path.join(
      LANDING_SCREENSHOTS_DIR,
      `anketa-preview-${locale}-dark.png`,
    ),
    fullPage: true,
    clip,
  });
  await page.locator('.btn-icon').first().click(); // back to light
  console.log(`Wrote e1o1-landing hero preview images for locale=${locale}.`);
}

console.log('Resetting john.doe / jane.doe from any previous run...');
resetAccounts();

console.log('Provisioning john.doe / jane.doe...');
const employeeToken = createActivationLink(EMPLOYEE_EMAIL);
const managerToken = createActivationLink(MANAGER_EMAIL);

const browser = await chromium.launch();
const employee = await activate(browser, employeeToken);
const manager = await activate(browser, managerToken);
console.log('Both accounts activated.');

// --- Login screenshot: a fresh, logged-out context ---
const loggedOutPage = await (
  await browser.newContext({ viewport: VIEWPORT })
).newPage();
await loggedOutPage.goto(`${BASE_URL}/`);
await loggedOutPage.waitForLoadState('networkidle');
await shot(loggedOutPage, 'login.png');
await loggedOutPage.close();

// --- Create the anketa (employee creates, manager is the counterpart) ---
await employee.goto(`${BASE_URL}/anketas/new`);
await employee
  .getByPlaceholder('Type a name or email to search…')
  .fill(MANAGER_EMAIL);
await employee.getByRole('button', { name: MANAGER_EMAIL }).click();
// Created in the near future (so the *empty* screenshot below doesn't show
// an unrelated "this meeting is overdue" banner) — backdated via a direct
// SQL update further down, right before archiving, so the *archived*
// screenshots show a sensible past meeting date and fall inside
// Report.svelte's backward-looking default date range
// (dateRangeForQuarterPreset) — a real gotcha already hit once before (see
// CLAUDE.md's Phase 6f notes) and hit again here before this two-step fix.
const meetingDate = new Date();
meetingDate.setDate(meetingDate.getDate() + 7);
await employee
  .locator('#meeting-date')
  .fill(
    `${String(meetingDate.getDate()).padStart(2, '0')}.${String(meetingDate.getMonth() + 1).padStart(2, '0')}.${meetingDate.getFullYear()}`,
  );
await employee.locator('#meeting-date').blur();

const [createRes] = await Promise.all([
  employee.waitForResponse(
    (res) =>
      res.request().method() === 'POST' && res.url().endsWith('/api/anketas'),
  ),
  employee.getByRole('button', { name: 'Create anketa' }).click(),
]);
const anketaId = (await createRes.json()).id;
await employee.waitForURL(/\/anketas\/[0-9a-f-]+$/);
console.log('Anketa created:', anketaId);

// --- Empty state, before anyone fills anything in ---
await shot(employee, 'anketa_employee_empty.png');

// --- Manager publishes first (mirrors the original set's README hero
// image, showing a published manager side waiting on the employee) ---
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
await publish(manager, anketaId, mgrSide);
// The top-level README.md hero image — cropped to just the "manager
// published, waiting on the employee" card, not the full page, matching
// how README.md's own <img width="600"> presents it.
await manager.waitForTimeout(300);
await manager.screenshot({
  path: path.join(OUT_DIR, 'anketa.png'),
  fullPage: true,
  clip: { x: 0, y: 0, width: VIEWPORT.width, height: 1370 },
});
console.log('Wrote anketa.png');

// --- Employee publishes ---
await employee.reload();
await employee.waitForLoadState('networkidle');
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
await publish(employee, anketaId, empSide);

// --- Outcomes: each added by its own owner ---
await employee.reload();
await employee.waitForLoadState('networkidle');
await employee
  .getByPlaceholder(/outcome/i)
  .fill(
    'Priya to draft a one-page proposal for the cross-team project by next meeting.',
  );
await Promise.all([
  employee.waitForResponse(
    (res) =>
      res.request().method() === 'PUT' &&
      res.url().endsWith(`/api/anketas/${anketaId}/outcomes`),
  ),
  employee.getByRole('button', { name: 'Add', exact: true }).click(),
]);

await manager.reload();
await manager.waitForLoadState('networkidle');
await manager
  .getByPlaceholder(/outcome/i)
  .fill(
    'Jordan to raise the dependency-intake process with the platform team lead.',
  );
await Promise.all([
  manager.waitForResponse(
    (res) =>
      res.request().method() === 'PUT' &&
      res.url().endsWith(`/api/anketas/${anketaId}/outcomes`),
  ),
  manager.getByRole('button', { name: 'Add', exact: true }).click(),
]);

// --- Goal + one checkpoint, added by the employee (goal author) ---
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
  .locator('.add-goal-row .date-input input[type=text]')
  .fill(
    `${String(targetDate.getDate()).padStart(2, '0')}.${String(targetDate.getMonth() + 1).padStart(2, '0')}.${targetDate.getFullYear()}`,
  );
await employee.locator('.add-goal-row .date-input input[type=text]').blur();
await Promise.all([
  employee.waitForResponse(
    (res) =>
      res.request().method() === 'POST' &&
      res.url().endsWith(`/api/anketas/${anketaId}/goals`),
  ),
  employee.getByRole('button', { name: 'Add goal', exact: false }).click(),
]);

await employee.reload();
await employee.waitForLoadState('networkidle');
const goalCard = employee.locator('.goal-card').first();
await goalCard
  .locator('.checkpoint-form input[type=text]')
  .fill('Kicked off scoping conversations with the platform team.');
await goalCard.locator('.checkpoint-form select').selectOption('on_track');
await Promise.all([
  employee.waitForResponse(
    (res) =>
      res.request().method() === 'PUT' &&
      res.url().endsWith(`/api/anketas/${anketaId}/goal-checkpoints`),
  ),
  goalCard.getByRole('button', { name: /add checkpoint/i }).click(),
]);
console.log('Content filled.');

// --- Backdate the meeting to 5 days ago, now that the empty-state
// screenshot (which needed a clean, non-overdue date) is already taken —
// see the meetingDate comment above for the full reasoning. ---
backdateMeetingDate(anketaId, 5);

// --- Archive (auto-creates the next, current anketa) ---
await employee.reload();
await employee.waitForLoadState('networkidle');
await Promise.all([
  employee.waitForResponse(
    (res) =>
      res.request().method() === 'POST' &&
      res.url().endsWith(`/api/anketas/${anketaId}/archive`),
  ),
  employee.getByRole('button', { name: 'Archive', exact: true }).click(),
]);
console.log('Archived.');

// --- Full, published, archived state — the main documentation shot ---
await employee.reload();
await employee.waitForLoadState('networkidle');
await shot(employee, 'anketa_employee.png');

// --- Dark mode, same page ---
await employee.locator('.btn-icon').first().click();
await shot(employee, 'dark_theme.png');
await employee.locator('.btn-icon').first().click(); // back to light

// --- Locale variants: the demo-mode fixture's own per-locale accounts,
// each with genuinely translated content — not this same English anketa
// with only the UI chrome switched via ?lang=, which would leave every
// free-text answer in English. ---
console.log('Resetting demo-mode data for the locale screenshots...');
resetDemoModeData();
for (const lang of DEMO_LOCALES) {
  const demoPage = await login(
    browser,
    `demo-employee-${lang}@example.com`,
    DEMO_PASSWORD,
    lang,
  );
  const demoList = await (
    await demoPage.request.get(`${BASE_URL}/api/anketas`)
  ).json();
  // meetingDate DESC-sorted; the first archived one is the most recent
  // cycle (the "resolution" half of the 2-cycle demo narrative — fully
  // published, with a goal/checkpoint/comment/outcomes), matching what
  // anketa_employee.png shows for the English narrative.
  const demoAnketaId = demoList.find((a) => a.archivedAt !== null).id;
  await demoPage.goto(`${BASE_URL}/anketas/${demoAnketaId}?lang=${lang}`);
  await demoPage.waitForLoadState('networkidle');
  await shot(demoPage, `anketa_${lang}.png`);
  // The demo fixture's own cycle already has a comment on this field baked
  // in (see demo-fixture-content.mjs), so no extra step is needed here
  // the way English needed one added further down.
  await captureHeroCrop(demoPage, lang);
  await demoPage.context().close();
}

// --- Anketa list: the archived one plus the fresh auto-created current one ---
await employee.goto(`${BASE_URL}/`);
await employee.waitForLoadState('networkidle');
await shot(employee, 'anketa_list.png', { fullPage: false });

// --- Report ---
await employee.goto(`${BASE_URL}/report`);
await employee.waitForLoadState('networkidle');
await employee.locator('button[type=submit]').first().click();
await employee.waitForTimeout(1500);
await shot(employee, 'report.png');

// English: this same anketa, but needs its own comment added first — none
// of the docs/ screenshots above show one (matching the original set), so
// it's added here, after all of those are already taken.
await manager.goto(`${BASE_URL}/anketas/${anketaId}`);
await manager.waitForLoadState('networkidle');
const counterpartSide = manager.locator('.side-card').nth(1);
const firstThread = counterpartSide.locator('.thread').first();
await firstThread.getByRole('button', { name: /comment/i }).click();
await firstThread
  .locator('input[type=text]')
  .fill('This is great news — congrats!');
await Promise.all([
  manager.waitForResponse(
    (res) =>
      res.request().method() === 'PUT' &&
      res.url().endsWith(`/api/anketas/${anketaId}/comments`),
  ),
  firstThread.getByRole('button', { name: 'Post' }).click(),
]);
await employee.goto(`${BASE_URL}/anketas/${anketaId}`);
await employee.waitForLoadState('networkidle');
await captureHeroCrop(employee, 'en');

await browser.close();
console.log('\nDone. encryption.png was intentionally skipped — see this script\'s own docblock.');
