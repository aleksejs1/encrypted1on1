import {
  test,
  expect,
  type Browser,
  type Locator,
  type Page,
  type Route,
} from '@playwright/test';
import { createActivationLink, uniqueEmail } from './helpers/provision.js';

const PASSWORD = 'correct horse battery staple 123';

// All tests in this file share one Browser across one worker (see
// playwright.config.ts) — activate() below never closes the contexts it
// creates, and neither did any test here, so every one of this file's real
// dual-actor journeys (2 contexts each) piled up for the rest of the run
// instead of being freed. General hygiene, not what actually caused a real
// CI failure investigated alongside this — that turned out to be the
// activation-complete rate limiter (see backend/.env.e2e's own comment on
// ACTIVATION_COMPLETE_RATE_LIMIT), not resource contention from this.
test.afterEach(async ({ browser }) => {
  await Promise.all(browser.contexts().map((context) => context.close()));
});

/** The `.block` on `side` whose `<h4>` title is exactly `title`. */
function questionBlock(side: Locator, title: string): Locator {
  return side.locator('.block', {
    has: side.page().getByRole('heading', { name: title, exact: true }),
  });
}

/**
 * The "Mood" block's notes field thread on a collapsed read-only `side`, with
 * the notes answered — the free text the tests below fill via the side's first
 * `<textarea>`. Anchored on that block and the field holding its rendered
 * answer, not on `.thread').first()`: which thread comes first depends on which
 * empty fields the collapsed view hides (GitHub issue #131). Not meant for a
 * side in edit mode, where the Markdown preview also uses `.answer-text`.
 */
function moodNotesThread(side: Locator): Locator {
  return questionBlock(side, 'Mood').locator(
    '.field:has(.answer-text) + .thread',
  );
}

const HELD_ENTER_TEXT = 'typed during a held Enter';

/**
 * Holds Enter on `editButton` (GitHub issue #151): the first press opens the
 * edit and moves focus into `editInput`. Types HELD_ENTER_TEXT there, then
 * sends auto-repeats (Playwright's repeated `keyboard.down` sets `repeat`),
 * which must not submit it. The caller then cancels and expects the row's
 * original text back. A repeat that got through would have saved
 * HELD_ENTER_TEXT, and the caller's row locator, which matches the original
 * text, would stop matching.
 */
async function holdEnterOnEdit(
  page: Page,
  editButton: Locator,
  editInput: Locator,
): Promise<void> {
  await editButton.focus();
  await page.keyboard.down('Enter');
  await expect(editInput).toBeFocused();
  await editInput.fill(HELD_ENTER_TEXT);
  await page.keyboard.down('Enter');
  await page.keyboard.down('Enter');
  await page.keyboard.up('Enter');
  await expect(editInput).toBeFocused();
}

async function activate(browser: Browser, token: string): Promise<Page> {
  const context = await browser.newContext();
  const page = await context.newPage();
  await page.goto(`/activate/${token}`);
  await page.locator('#act-password').fill(PASSWORD);
  await page.locator('#act-confirm').fill(PASSWORD);
  await page.getByRole('button', { name: 'Activate' }).click();
  await page.waitForURL('/');
  return page;
}

/**
 * One full, real dual-actor journey — two genuinely independent browser
 * contexts (separate cookies/session, exactly like two different people in
 * two different browsers), driving the real UI against the real dev stack.
 * Everything this project has previously called "verified end-to-end with
 * real crypto" ran as a Node script or PHP's ext-sodium — never inside an
 * actual browser. This is the first time the real WebAssembly crypto
 * (argon2id, X25519, XChaCha20-Poly1305, all via libsodium-wrappers-sumo)
 * is exercised as it actually runs for a real user: through real form
 * inputs, in a real Chromium tab, round-tripping through the real backend.
 */
test('employee and manager complete an anketa across two independent sessions', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee');
  const managerEmail = uniqueEmail('manager');
  const employeeToken = createActivationLink(employeeEmail);
  const managerToken = createActivationLink(managerEmail);

  const employee = await activate(browser, employeeToken);
  const manager = await activate(browser, managerToken);

  // Employee creates a new anketa with the manager as counterpart. Exercises
  // apiGetAllPages() (frontend/src/api/client.ts) — the typeahead has to find
  // the manager regardless of how many other accounts already exist in this
  // dev DB, not just whichever ones happen to land on page 1.
  await employee.goto('/anketas/new');
  const counterpartInput = employee.getByPlaceholder(
    'Type a name or email to search…',
  );
  await counterpartInput.fill(managerEmail);
  await employee.getByRole('button', { name: managerEmail }).click();

  const meetingDate = new Date();
  meetingDate.setDate(meetingDate.getDate() + 3);
  // DateInput (frontend/src/design/DateInput.svelte) is a text field
  // parsed per the user's date-format preference, not a native
  // `<input type="date">` — DEFAULT_DATE_FORMAT is 'dmy_dot' (DD.MM.YYYY).
  const dd = String(meetingDate.getDate()).padStart(2, '0');
  const mm = String(meetingDate.getMonth() + 1).padStart(2, '0');
  const meetingDateInput = employee.locator('#meeting-date');
  await meetingDateInput.fill(`${dd}.${mm}.${meetingDate.getFullYear()}`);
  // DateInput only parses on blur (commitText()) — the "Create anketa"
  // button starts out disabled, and a disabled button can't take focus to
  // blur this field for us, so it must be done explicitly first.
  await meetingDateInput.blur();
  await employee.getByRole('button', { name: 'Create anketa' }).click();
  await employee.waitForURL(/\/anketas\/[0-9a-f-]+$/);
  const anketaUrl = employee.url();

  // Employee publishes their side with a unique marker.
  const employeeMarker = `E2E-MARKER-EMPLOYEE-${Date.now()}`;
  const employeeMySide = employee.locator('.side-card').first();
  await employeeMySide.locator('textarea').first().fill(employeeMarker);
  await employeeMySide.getByRole('button', { name: 'Publish' }).click();
  await expect(employeeMySide.getByText('Published')).toBeVisible();

  // Manager — a completely separate session — opens the same anketa and must
  // see the employee's marker decrypt correctly on the counterpart side. A
  // published/readonly text answer renders as sanitized Markdown inside
  // AnswerField.svelte's `.answer-text` (frontend/src/anketa/markdown.ts),
  // not a <textarea> — only the editing state (my own unpublished/
  // being-edited side, above) still has a real <textarea>, inside
  // MarkdownEditor.svelte's "Source" tab.
  await manager.goto(anketaUrl);
  const managerCounterpartSide = manager.locator('.side-card').nth(1);
  await expect(
    managerCounterpartSide.locator('.answer-text').first(),
  ).toHaveText(employeeMarker);

  // Employee edits their already-published answer — the editable-after-publish
  // feature (docs/decisions/2026-09-07-editable-published-anketa-answers.md).
  // Real re-encryption with the same anketa key through the actual Edit/Save UI,
  // not just PUT /api/anketas/{id}/answers called directly.
  const employeeEditedMarker = `E2E-MARKER-EMPLOYEE-EDITED-${Date.now()}`;
  await employeeMySide.getByRole('button', { name: 'Edit' }).click();
  await employeeMySide.locator('textarea').first().fill(employeeEditedMarker);
  await employeeMySide.getByRole('button', { name: 'Save' }).click();
  await expect(employeeMySide.getByText('Published')).toBeVisible();

  // Manager — a separate session — reloads and sees the edited content, not the
  // original marker: the edit genuinely round-tripped through the server,
  // re-encrypted under the shared anketa key, not merely updated in local state.
  await manager.reload();
  await expect(
    managerCounterpartSide.locator('.answer-text').first(),
  ).toHaveText(employeeEditedMarker);

  // Manager publishes their own side with a second marker.
  const managerMarker = `E2E-MARKER-MANAGER-${Date.now()}`;
  const managerMySide = manager.locator('.side-card').first();
  await managerMySide.locator('textarea').first().fill(managerMarker);
  await managerMySide.getByRole('button', { name: 'Publish' }).click();
  await expect(managerMySide.getByText('Published')).toBeVisible();

  // Manager comments on the employee's mood notes (still visible on the
  // counterpart side after publishing their own side).
  const managerThread = moodNotesThread(managerCounterpartSide);
  // No comments yet on this field — starts collapsed (CommentThread.svelte's
  // `expanded` default), so the add-comment input isn't there until the
  // toggle is clicked.
  await expect(managerThread.locator('input[type=text]')).not.toBeVisible();
  await managerThread.getByRole('button', { name: /comment/i }).click();
  await managerThread.locator('input[type=text]').fill('looks good to me');
  await managerThread.getByRole('button', { name: 'Post' }).click();
  await expect(managerThread.getByText('looks good to me')).toBeVisible();

  // Manager edits their own comment through the real Edit/Save UI (typing,
  // clicking — not just the pure editComment() unit tests in comments.ts) —
  // still their own session/tab, real WASM crypto re-encrypting the whole
  // commentsBlob on Save.
  await managerThread.getByRole('button', { name: 'Edit' }).click();
  await managerThread
    .locator('.edit-form input[type=text]')
    .fill('looks good to me, approved');
  await managerThread.getByRole('button', { name: 'Save' }).click();
  await expect(
    managerThread.getByText('looks good to me, approved'),
  ).toBeVisible();
  await expect(
    managerThread.getByText('looks good to me', { exact: true }),
  ).not.toBeVisible();

  // A second comment, posted then deleted by its own author through the
  // real two-step Delete/Confirm-delete UI — scoped to its own .comment row
  // so it doesn't touch the first (edited) comment sitting right next to it.
  await managerThread
    .locator('input[type=text]')
    .fill('actually, scratch that');
  await managerThread.getByRole('button', { name: 'Post' }).click();
  const scratchComment = managerThread.locator('.comment', {
    hasText: 'actually, scratch that',
  });
  await expect(scratchComment).toBeVisible();
  await scratchComment.getByRole('button', { name: 'Delete' }).click();
  await scratchComment.getByRole('button', { name: 'Confirm delete' }).click();
  await expect(
    managerThread.getByText('actually, scratch that'),
  ).not.toBeVisible();
  // The edit survived the unrelated add+delete right next to it.
  await expect(
    managerThread.getByText('looks good to me, approved'),
  ).toBeVisible();

  // Employee reloads: sees the manager's marker on the counterpart side, and
  // the manager's *edited* comment (not the pre-edit text, not the deleted
  // one) on their own (now-published) side — the second encrypted
  // shared-blob channel (commentsBlob) round-tripping for real, including
  // edit/delete.
  await employee.reload();
  const employeeCounterpartSide = employee.locator('.side-card').nth(1);
  await expect(
    employeeCounterpartSide.locator('.answer-text').first(),
  ).toHaveText(managerMarker);

  const employeeThread = moodNotesThread(
    employee.locator('.side-card').first(),
  );
  // No click needed: this thread already has the manager's comment, so it
  // renders expanded by default (CommentThread.svelte's `expanded` now
  // seeds from `comments.length > 0` instead of always `false`) — clicking
  // the toggle here would collapse it instead of opening it.
  await expect(
    employeeThread.getByText('looks good to me, approved'),
  ).toBeVisible();
  await expect(
    employeeThread.getByText('actually, scratch that'),
  ).not.toBeVisible();
  await expect(employeeThread.getByText(`${managerEmail}:`)).toBeVisible();
  // Employee isn't the comment's author — no Edit/Delete buttons offered on
  // someone else's comment (CommentThread.svelte's currentUserId gate).
  await expect(
    employeeThread.getByRole('button', { name: 'Edit' }),
  ).toHaveCount(0);
  await expect(
    employeeThread.getByRole('button', { name: 'Delete' }),
  ).toHaveCount(0);
});

/**
 * Coverage for editing an existing list-entry (achievements/growth/discuss)
 * in place, added because these fields previously only supported add/remove
 * (see the "achievements/growth entries have no edit" issue) — deleting and
 * re-adding an entry to fix a typo would silently re-stamp its date and move
 * it to the end of the log, corrupting the timeline of a field whose whole
 * point is being an accurate, dated record. Drives the real Edit/Save UI
 * (not the pure mutation directly — there's no exported/unit-tested function
 * for it, unlike editOutcome/editComment, since it stays an inline closure
 * alongside its untested addListEntry/removeListEntry siblings) and confirms
 * the edit survives a real publish + re-encrypt + a separate session
 * decrypting it, the same round-trip standard as the rest of this file.
 */
test('achievements list entry can be edited in place, and the edit survives publish', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-editentry');
  const managerEmail = uniqueEmail('manager-editentry');
  const employeeToken = createActivationLink(employeeEmail);
  const managerToken = createActivationLink(managerEmail);

  const employee = await activate(browser, employeeToken);
  const manager = await activate(browser, managerToken);

  await employee.goto('/anketas/new');
  await employee
    .getByPlaceholder('Type a name or email to search…')
    .fill(managerEmail);
  await employee.getByRole('button', { name: managerEmail }).click();

  const meetingDate = new Date();
  meetingDate.setDate(meetingDate.getDate() + 3);
  const dd = String(meetingDate.getDate()).padStart(2, '0');
  const mm = String(meetingDate.getMonth() + 1).padStart(2, '0');
  const meetingDateInput = employee.locator('#meeting-date');
  await meetingDateInput.fill(`${dd}.${mm}.${meetingDate.getFullYear()}`);
  await meetingDateInput.blur();
  await employee.getByRole('button', { name: 'Create anketa' }).click();
  await employee.waitForURL(/\/anketas\/[0-9a-f-]+$/);
  const anketaUrl = employee.url();

  // "Achievements" (not "Achievements worth recognizing", the manager-side
  // field) is unambiguous within the employee's own side-card.
  const achievementsBlock = employee
    .locator('.side-card')
    .first()
    .locator('.block', { hasText: 'Achievements' });
  const originalText = `E2E-ENTRY-ORIGINAL-${Date.now()}`;
  const editedText = `E2E-ENTRY-EDITED-${Date.now()}`;
  await achievementsBlock.getByPlaceholder('Add an entry…').fill(originalText);
  await achievementsBlock.getByRole('button', { name: 'Add' }).click();
  // Anchored by position, not by hasText: 'Edit' swaps the entry's text span
  // for an input carrying the text as a *value*, not text content, so a
  // hasText-filtered locator would stop matching its own row mid-edit. This
  // is the only entry in the list at this point.
  const entryRow = achievementsBlock.locator('.entry').first();
  await expect(entryRow).toContainText(originalText);
  const originalDate = await entryRow.locator('.entry-date').innerText();

  await entryRow.getByRole('button', { name: 'Edit' }).click();
  await entryRow.locator('.entry-edit-input').fill(editedText);
  await entryRow.getByRole('button', { name: 'Save' }).click();

  // Renamed in place: same row, edited text, and — unlike a delete-and-readd —
  // the same original date, since editing must not disturb the entry's
  // position or dated meaning in the log.
  await expect(entryRow.locator('.entry-text')).toHaveText(editedText);
  await expect(entryRow.locator('.entry-date')).toHaveText(originalDate);

  // The initial "Publish" button must also refuse to fire while an entry's
  // inline edit sits open and uncommitted — otherwise Publish would persist
  // the pre-edit answers and silently discard whatever's mid-typed here.
  const employeeMySide = employee.locator('.side-card').first();
  const publishButton = employeeMySide.getByRole('button', {
    name: 'Publish',
  });
  await entryRow.getByRole('button', { name: 'Edit' }).click();
  await expect(publishButton).toBeDisabled();
  await entryRow.getByRole('button', { name: 'Cancel' }).click();
  await expect(publishButton).toBeEnabled();

  // Publish, then a completely separate manager session must see the edited
  // text (not the original) — confirming the edit genuinely round-tripped
  // through the server's saveDraft()/publish() blob save, re-encrypted under
  // the shared anketa key, not merely local component state.
  await publishButton.click();
  await expect(employeeMySide.getByText('Published')).toBeVisible();

  // Open the outer per-side "Edit" (the editable-after-publish feature), then
  // open the *same entry's* inline edit again. The outer "Save" must be
  // disabled while that inline edit sits open and uncommitted — otherwise
  // clicking outer Save would persist the pre-edit text while silently
  // discarding whatever's mid-typed in the entry's own edit box.
  const postPublishEditedText = `E2E-ENTRY-POST-PUBLISH-EDIT-${Date.now()}`;
  await employeeMySide.getByRole('button', { name: 'Edit' }).click();
  const outerSaveButton = employeeMySide
    .locator('.answers-edit-actions')
    .getByRole('button', { name: 'Save' });
  await entryRow.getByRole('button', { name: 'Edit' }).click();
  await expect(outerSaveButton).toBeDisabled();
  await entryRow.locator('.entry-edit-input').fill(postPublishEditedText);
  await entryRow.getByRole('button', { name: 'Save' }).click();
  await expect(outerSaveButton).toBeEnabled();
  await outerSaveButton.click();
  await expect(employeeMySide.getByText('Published')).toBeVisible();

  await manager.goto(anketaUrl);
  const managerCounterpartAchievements = manager
    .locator('.side-card')
    .nth(1)
    .locator('.block', { hasText: 'Achievements' });
  await expect(
    managerCounterpartAchievements.getByText(postPublishEditedText),
  ).toBeVisible();
  await expect(
    managerCounterpartAchievements.getByText(editedText, { exact: true }),
  ).not.toBeVisible();
  await expect(
    managerCounterpartAchievements.getByText(originalText),
  ).not.toBeVisible();
});

/**
 * Regression coverage for the "Change date" toggle: before this, an anketa's
 * meeting date could only be moved from the overdue-only reschedule card
 * (Anketa.svelte's `isOverdue` gate), so a participant who knew *in advance*
 * they'd have to miss an upcoming meeting had no way to move it — only the
 * PUT /api/anketas/{id}/meeting-date endpoint already supported it. This
 * drives the real UI toggle end to end against the real backend, including
 * the Cancel path leaving the date untouched.
 */
test('participant can change the meeting date on an upcoming (non-overdue) anketa', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-reschedule');
  const managerEmail = uniqueEmail('manager-reschedule');
  const employeeToken = createActivationLink(employeeEmail);
  const managerToken = createActivationLink(managerEmail);

  const employee = await activate(browser, employeeToken);
  // Only needs to exist as a real, keyed counterpart for the anketa to be
  // created against — the rest of this test drives the employee alone.
  await activate(browser, managerToken);

  await employee.goto('/anketas/new');
  await employee
    .getByPlaceholder('Type a name or email to search…')
    .fill(managerEmail);
  await employee.getByRole('button', { name: managerEmail }).click();

  const meetingDate = new Date();
  meetingDate.setDate(meetingDate.getDate() + 3);
  const dd = String(meetingDate.getDate()).padStart(2, '0');
  const mm = String(meetingDate.getMonth() + 1).padStart(2, '0');
  const meetingDateInput = employee.locator('#meeting-date');
  await meetingDateInput.fill(`${dd}.${mm}.${meetingDate.getFullYear()}`);
  await meetingDateInput.blur();
  await employee.getByRole('button', { name: 'Create anketa' }).click();
  await employee.waitForURL(/\/anketas\/[0-9a-f-]+$/);

  // Upcoming meeting: the overdue-only reschedule card is absent, and the
  // lightweight "Change date" toggle is what's offered instead.
  await expect(employee.locator('.overdue-card')).toHaveCount(0);
  const changeDateButton = employee.getByRole('button', {
    name: 'Change date',
  });
  await expect(changeDateButton).toBeVisible();

  // Open the toggle, then back out via Cancel — the date must stay
  // untouched and the toggle must collapse back to its closed state.
  await changeDateButton.click();
  const rescheduleRow = employee.locator('.reschedule-row');
  await expect(rescheduleRow).toBeVisible();
  await rescheduleRow.getByRole('button', { name: 'Cancel' }).click();
  await expect(rescheduleRow).toHaveCount(0);
  await expect(employee.locator('p.meta')).toContainText(
    `${dd}.${mm}.${meetingDate.getFullYear()}`,
  );

  // Reopen and actually move the date forward.
  await changeDateButton.click();
  const newMeetingDate = new Date();
  newMeetingDate.setDate(newMeetingDate.getDate() + 10);
  const newDd = String(newMeetingDate.getDate()).padStart(2, '0');
  const newMm = String(newMeetingDate.getMonth() + 1).padStart(2, '0');
  const newYyyy = newMeetingDate.getFullYear();

  const dateField = employee.locator('.reschedule-row input[type=text]');
  await dateField.fill(`${newDd}.${newMm}.${newYyyy}`);
  await dateField.blur();
  await employee
    .locator('.reschedule-row')
    .getByRole('button', { name: 'Reschedule' })
    .click();

  // The inline form collapses back to the closed "Change date" toggle, and
  // the displayed meeting date reflects the new value — a real round trip
  // through PUT /api/anketas/{id}/meeting-date, not just local UI state.
  await expect(employee.locator('.reschedule-row')).toHaveCount(0);
  await expect(changeDateButton).toBeVisible();
  await expect(employee.locator('p.meta')).toContainText(
    `${newDd}.${newMm}.${newYyyy}`,
  );

  // A page reload confirms the new date was actually persisted server-side,
  // not just optimistically patched into local state.
  await employee.reload();
  await expect(employee.locator('p.meta')).toContainText(
    `${newDd}.${newMm}.${newYyyy}`,
  );
});

/**
 * Coverage for the anketa page's live-update mechanism (see
 * private/live-updates-proposal.md, not tracked in git) — a poll of
 * GET /api/anketas/{id}/live-state that refreshes whichever sections changed
 * without a manual page reload. Two real, independent sessions, neither
 * reloaded once the polling starts: the manager's already-open tab picks up
 * the employee's published-answer edit, and the employee's already-open tab
 * picks up the manager's new comment (with the brief highlight cue —
 * private/live-updates-proposal.md §7). Both are real re-encrypt/decrypt
 * round trips under the real anketa key, not local state mutated directly.
 */
test('published answer edits and new comments appear on an already-open tab without reloading', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-live');
  const managerEmail = uniqueEmail('manager-live');
  const employeeToken = createActivationLink(employeeEmail);
  const managerToken = createActivationLink(managerEmail);

  const employee = await activate(browser, employeeToken);
  const manager = await activate(browser, managerToken);

  await employee.goto('/anketas/new');
  await employee
    .getByPlaceholder('Type a name or email to search…')
    .fill(managerEmail);
  await employee.getByRole('button', { name: managerEmail }).click();

  const meetingDate = new Date();
  meetingDate.setDate(meetingDate.getDate() + 3);
  const dd = String(meetingDate.getDate()).padStart(2, '0');
  const mm = String(meetingDate.getMonth() + 1).padStart(2, '0');
  const meetingDateInput = employee.locator('#meeting-date');
  await meetingDateInput.fill(`${dd}.${mm}.${meetingDate.getFullYear()}`);
  await meetingDateInput.blur();
  await employee.getByRole('button', { name: 'Create anketa' }).click();
  await employee.waitForURL(/\/anketas\/[0-9a-f-]+$/);
  const anketaUrl = employee.url();

  const markerA = `E2E-LIVE-MARKER-A-${Date.now()}`;
  const employeeMySide = employee.locator('.side-card').first();
  await employeeMySide.locator('textarea').first().fill(markerA);
  await employeeMySide.getByRole('button', { name: 'Publish' }).click();
  await expect(employeeMySide.getByText('Published')).toBeVisible();

  // Manager opens the anketa once (a real page load, not itself a live
  // update) and leaves this tab open for the rest of the test — every
  // further assertion on this page has to arrive via the poll, not a reload.
  await manager.goto(anketaUrl);
  const managerCounterpartSide = manager.locator('.side-card').nth(1);
  await expect(
    managerCounterpartSide.locator('.answer-text').first(),
  ).toHaveText(markerA);

  // Employee edits their already-published answer, in the same still-open
  // tab. The counterpart's own answers are never locally edited, so this
  // section has no busy-gate to wait out — it should just show up.
  const markerB = `E2E-LIVE-MARKER-B-${Date.now()}`;
  await employeeMySide.getByRole('button', { name: 'Edit' }).click();
  await employeeMySide.locator('textarea').first().fill(markerB);
  await employeeMySide.getByRole('button', { name: 'Save' }).click();
  await expect(employeeMySide.getByText('Published')).toBeVisible();

  // No manager.reload() here — this has to arrive via the live-state poll.
  await expect(
    managerCounterpartSide.locator('.answer-text').first(),
  ).toHaveText(markerB, { timeout: 8000 });

  // Manager comments on that same field from their already-open tab.
  const managerThread = moodNotesThread(managerCounterpartSide);
  await managerThread.getByRole('button', { name: /comment/i }).click();
  await managerThread.locator('input[type=text]').fill('nice progress');
  await managerThread.getByRole('button', { name: 'Post' }).click();
  await expect(managerThread.getByText('nice progress')).toBeVisible();

  // Employee's own tab (still open on the same field, myPublished so its own
  // CommentThread instance is rendered) picks up the new comment without a
  // reload, and briefly highlights it (private/live-updates-proposal.md §7).
  const employeeThread = moodNotesThread(employeeMySide);
  const newComment = employeeThread.locator('.comment', {
    hasText: 'nice progress',
  });
  await expect(newComment).toBeVisible({ timeout: 8000 });
  await expect(newComment).toHaveClass(/recently-arrived/, { timeout: 2000 });
});

/**
 * Regression coverage for a real bug an independent review round caught in this
 * same feature: the live-update poll flipping `archived` to true never reset an
 * in-progress `editingMyAnswers` session, so a field stayed editable (a real
 * `<textarea>`) with no Save/Cancel button left to reach — the exact "independent
 * booleans combining into a state nothing else produces" pattern CLAUDE.md's
 * working-style section calls out from the multi-tab-unlock incident. Drives the
 * real archive flow from a separate session while the first tab has an unsaved
 * edit open, with no reload on the edited tab — this has to arrive via the poll.
 */
test('counterpart archiving mid-edit exits edit mode on an already-open tab without reloading', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-archive-live');
  const managerEmail = uniqueEmail('manager-archive-live');
  const employeeToken = createActivationLink(employeeEmail);
  const managerToken = createActivationLink(managerEmail);

  const employee = await activate(browser, employeeToken);
  const manager = await activate(browser, managerToken);

  await employee.goto('/anketas/new');
  await employee
    .getByPlaceholder('Type a name or email to search…')
    .fill(managerEmail);
  await employee.getByRole('button', { name: managerEmail }).click();

  const meetingDate = new Date();
  meetingDate.setDate(meetingDate.getDate() + 3);
  const dd = String(meetingDate.getDate()).padStart(2, '0');
  const mm = String(meetingDate.getMonth() + 1).padStart(2, '0');
  const meetingDateInput = employee.locator('#meeting-date');
  await meetingDateInput.fill(`${dd}.${mm}.${meetingDate.getFullYear()}`);
  await meetingDateInput.blur();
  await employee.getByRole('button', { name: 'Create anketa' }).click();
  await employee.waitForURL(/\/anketas\/[0-9a-f-]+$/);
  const anketaUrl = employee.url();

  const originalMarker = `E2E-ARCHIVE-ORIGINAL-${Date.now()}`;
  const employeeMySide = employee.locator('.side-card').first();
  await employeeMySide.locator('textarea').first().fill(originalMarker);
  await employeeMySide.getByRole('button', { name: 'Publish' }).click();
  await expect(employeeMySide.getByText('Published')).toBeVisible();

  // Employee opens edit mode and types a change, but never clicks Save —
  // this has to still be sitting open when the counterpart archives below.
  await employeeMySide.getByRole('button', { name: 'Edit' }).click();
  await employeeMySide
    .locator('textarea')
    .first()
    .fill(`${originalMarker}-UNSAVED-EDIT`);
  await expect(
    employeeMySide.getByRole('button', { name: 'Save' }),
  ).toBeVisible();
  // Archiving from this tab waits for the edit to be saved or cancelled —
  // otherwise the edit would become unsaveable (GitHub issue #130 review).
  await expect(
    employee.getByRole('button', { name: 'Archive' }),
  ).toBeDisabled();

  // Manager — a separate session — archives the anketa (skipping next-cycle
  // creation, which needs no client-side key generation and keeps this test
  // focused on the archive-mid-edit race itself).
  await manager.goto(anketaUrl);
  // force: true — the checkbox's own wrapping <label> intercepts the
  // pointer event for its custom styling, same as a real user clicking
  // anywhere on the label still toggles the underlying native checkbox.
  await manager
    .getByRole('checkbox', { name: "Don't create the next meeting" })
    .check({ force: true });
  await manager.getByRole('button', { name: 'Archive' }).click();
  await expect(manager.getByRole('button', { name: 'Archive' })).toHaveCount(0);

  // No employee.reload() — this has to arrive via the live-state poll. Once
  // it does, editingMyAnswers must have been reset: no Save/Cancel/Edit
  // button left reachable, and the field itself is back to its readonly,
  // rendered-as-text form (AnswerField swaps the real <textarea> out for
  // `.answer-text` once readonly — see AnswerField.svelte) — not still an
  // editable textarea with nothing able to reach it, which is exactly the
  // bug this test guards against.
  await expect(
    employeeMySide.getByRole('button', { name: 'Save' }),
  ).toHaveCount(0, { timeout: 8000 });
  await expect(
    employeeMySide.getByRole('button', { name: 'Cancel' }),
  ).toHaveCount(0);
  await expect(
    employeeMySide.getByRole('button', { name: 'Edit' }),
  ).toHaveCount(0);
  await expect(employeeMySide.locator('textarea')).toHaveCount(0);
  // The unsaved edit was never sent anywhere (no more editable field to send
  // it from) — it just stays displayed, readonly, exactly matching the
  // existing pre-this-feature behavior for the same "archived mid-edit"
  // case discovered reactively via a 409 (handleSaveAnswersEdit's own catch
  // block leaves myAnswers as-is too, rather than reverting to
  // answersBeforeEdit) — this test isn't asserting new revert behavior, only
  // that edit mode itself was correctly exited.
  await expect(employeeMySide.locator('.answer-text').first()).toHaveText(
    `${originalMarker}-UNSAVED-EDIT`,
  );
});

/**
 * GitHub issue #130: archiving an anketa the counterpart already archived used
 * to succeed a second time and create a second successor. The server now
 * answers 409, and the page must treat that as "archived", with a notice
 * rather than a generic error. The employee tab's live-state poll is held so
 * its Archive button is still there to click, which is the window a real user
 * hits between the counterpart's archive and the next poll tick. The page
 * must show the counterpart's `missed` flag, not this click's, from the 409
 * itself; and the held poll, answered afterwards with its stale pre-archive
 * state, must not bring the Archive button back.
 */
test('archiving an anketa the counterpart already archived shows it as archived, with a notice', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-archive-twice');
  const managerEmail = uniqueEmail('manager-archive-twice');
  const employeeToken = createActivationLink(employeeEmail);
  const managerToken = createActivationLink(managerEmail);

  const employee = await activate(browser, employeeToken);
  const manager = await activate(browser, managerToken);

  const anketaUrl = await createAnketa(employee, managerEmail, 3);

  // Held, not answered, so this tab can't learn about the archive yet.
  const heldPolls: Route[] = [];
  await employee.route('**/live-state', (route) => {
    heldPolls.push(route);
  });
  await expect
    .poll(() => heldPolls.length, { timeout: 8000 })
    .toBeGreaterThan(0);

  // The counterpart cancels it as missed (through the API: the overdue card's
  // button only appears on an overdue anketa), with no successor.
  const managerCsrf = (
    (await (await manager.request.get('/api/csrf-token')).json()) as {
      token: string;
    }
  ).token;
  const anketaId = anketaUrl.split('/').pop();
  const managerArchive = await manager.request.post(
    `/api/anketas/${anketaId}/archive`,
    {
      data: { missed: true, skipNextMeeting: true },
      headers: { 'X-CSRF-Token': managerCsrf },
    },
  );
  expect(managerArchive.status()).toBe(200);

  const archiveResponse = employee.waitForResponse(
    (response) =>
      response.url().endsWith('/archive') &&
      response.request().method() === 'POST',
  );
  await employee.getByRole('button', { name: 'Archive' }).click();
  expect((await archiveResponse).status()).toBe(409);

  const archiveButton = employee.getByRole('button', { name: 'Archive' });
  await expect(archiveButton).toHaveCount(0);
  // Not the generic "Could not archive." — a notice that this click's own
  // choices may not be what got applied.
  await expect(
    employee.getByRole('alert').filter({ hasText: /already archived/ }),
  ).toBeVisible();
  // The counterpart's `missed`, not this click's, straight from the 409 —
  // every poll is still held.
  await expect(employee.getByText('missed', { exact: true })).toBeVisible();

  // Answer the held poll with its stale, pre-archive state. Every later poll
  // is held too: one is only sent once the stale one has been fully handled,
  // and holding it keeps a fresh response from re-archiving the page before
  // the check below.
  expect(heldPolls).toHaveLength(1);
  const stalePoll = heldPolls[0];
  const staleResponse = await stalePoll.fetch();
  await stalePoll.fulfill({
    response: staleResponse,
    json: {
      ...((await staleResponse.json()) as object),
      archivedAt: null,
      missed: false,
    },
  });
  await expect
    .poll(() => heldPolls.length, { timeout: 8000 })
    .toBeGreaterThan(1);
  await expect(archiveButton).toHaveCount(0);
  await expect(employee.getByText('missed', { exact: true })).toBeVisible();
  await Promise.all(heldPolls.slice(1).map((route) => route.continue()));
  await employee.unroute('**/live-state');

  // This click created no successor: the counterpart skipped the next meeting.
  const anketas = (await (
    await employee.request.get('/api/anketas')
  ).json()) as { archivedAt: string | null }[];
  expect(anketas).toHaveLength(1);
});

/**
 * Regression coverage for a second real bug an independent review round
 * caught: the *pre-publish* draft view never consulted `archived` at all —
 * `{#if !myPublished}` always won regardless of archived state, so a side
 * that never published could keep autosaving a draft and even successfully
 * publish onto an anketa the counterpart had already archived, with no
 * error anywhere (saveDraft()/publish() on the backend only checked
 * isPublished(), never isArchived()). The diff had modeled and tested the
 * symmetric post-publish case in depth but missed this pre-publish half of
 * the same "editing never offered once archived" rule. Fixed at the root
 * (backend now rejects both with 409) and in the UI (the draft/Publish area
 * now shows an "archived" tag instead once archived, checked ahead of
 * `!myPublished`). No reload on the drafting tab — this has to arrive via
 * the live-state poll, exactly the routine-not-edge-case scenario this
 * whole feature creates.
 */
test('counterpart archiving an anketa the other side never published on disables the draft on an already-open tab', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-archive-draft');
  const managerEmail = uniqueEmail('manager-archive-draft');
  const employeeToken = createActivationLink(employeeEmail);
  const managerToken = createActivationLink(managerEmail);

  const employee = await activate(browser, employeeToken);
  const manager = await activate(browser, managerToken);

  await employee.goto('/anketas/new');
  await employee
    .getByPlaceholder('Type a name or email to search…')
    .fill(managerEmail);
  await employee.getByRole('button', { name: managerEmail }).click();

  const meetingDate = new Date();
  meetingDate.setDate(meetingDate.getDate() + 3);
  const dd = String(meetingDate.getDate()).padStart(2, '0');
  const mm = String(meetingDate.getMonth() + 1).padStart(2, '0');
  const meetingDateInput = employee.locator('#meeting-date');
  await meetingDateInput.fill(`${dd}.${mm}.${meetingDate.getFullYear()}`);
  await meetingDateInput.blur();
  await employee.getByRole('button', { name: 'Create anketa' }).click();
  await employee.waitForURL(/\/anketas\/[0-9a-f-]+$/);
  const anketaUrl = employee.url();

  // Employee starts drafting but never publishes — this tab stays open,
  // never reloaded, for the rest of the test.
  const employeeMySide = employee.locator('.side-card').first();
  await employeeMySide
    .locator('textarea')
    .first()
    .fill('a draft nobody will ever publish');
  await expect(
    employeeMySide.getByRole('button', { name: 'Publish' }),
  ).toBeVisible();

  // Manager — a separate session — archives the anketa (skipping next-cycle
  // creation, same as the other archive-mid-edit test above).
  await manager.goto(anketaUrl);
  await manager
    .getByRole('checkbox', { name: "Don't create the next meeting" })
    .check({ force: true });
  await manager.getByRole('button', { name: 'Archive' }).click();
  await expect(manager.getByRole('button', { name: 'Archive' })).toHaveCount(0);

  // No employee.reload() — this has to arrive via the live-state poll. The
  // draft textarea and Publish button must both disappear, replaced by the
  // "archived" tag — not still an editable, publishable draft with nothing
  // able to reach the fact it's now closed.
  await expect(
    employeeMySide.getByRole('button', { name: 'Publish' }),
  ).toHaveCount(0, { timeout: 8000 });
  await expect(employeeMySide.locator('textarea')).toHaveCount(0);
  await expect(
    employeeMySide.getByText('archived', { exact: false }),
  ).toBeVisible();
});

/**
 * Creates an anketa from `creator`'s side (always as the employee — the create
 * form's default role) against `counterpartEmail`, `daysAhead` days out, via
 * the real /anketas/new form, and returns its URL. `templateLabel` is the
 * picker's visible label (createAnketa.template* in en.json); omitted, the
 * form's own default ('regular') is left selected. Only the meeting-templates
 * tests below use this — the older tests above predate it and still inline
 * the same steps.
 */
async function createAnketa(
  creator: Page,
  counterpartEmail: string,
  daysAhead: number,
  templateLabel?: string,
): Promise<string> {
  await creator.goto('/anketas/new');
  await creator
    .getByPlaceholder('Type a name or email to search…')
    .fill(counterpartEmail);
  await creator.getByRole('button', { name: counterpartEmail }).click();

  if (templateLabel) {
    // Clicks the wrapping <label>, the way a real user picks it: the native
    // radio itself is visually hidden behind the custom `.dot`
    // (components.css's .radio), so Playwright can't click it directly.
    await creator.locator('label.radio', { hasText: templateLabel }).click();
    await expect(
      creator.getByRole('radio', { name: templateLabel }),
    ).toBeChecked();
  }

  const meetingDate = new Date();
  meetingDate.setDate(meetingDate.getDate() + daysAhead);
  const dd = String(meetingDate.getDate()).padStart(2, '0');
  const mm = String(meetingDate.getMonth() + 1).padStart(2, '0');
  const meetingDateInput = creator.locator('#meeting-date');
  await meetingDateInput.fill(`${dd}.${mm}.${meetingDate.getFullYear()}`);
  await meetingDateInput.blur();
  await creator.getByRole('button', { name: 'Create anketa' }).click();
  await creator.waitForURL(/\/anketas\/[0-9a-f-]+$/);
  return creator.url();
}

/**
 * Coverage for anketa meeting templates (GitHub issues #102–#107) through the
 * real UI and real crypto, which the unit tests (questions.test.ts) and the
 * backend's functional tests can't give on their own: the picker's choice has
 * to survive the round trip to the server and back to *both* participants'
 * browsers, since each side independently renders its form — and the
 * counterpart's decrypted answers — from `detail.templateKey`. A mismatch
 * would show one side's answers against the wrong question set.
 *
 * Also the only e2e coverage of archiving *with* a next meeting: every other
 * archive in this file ticks "Don't create the next meeting", so the
 * client-side next-key generation/sealing in Anketa.svelte's handleArchive()
 * was never exercised in a browser. Here the successor is opened and
 * answered from both sides, proving the counterpart's sealed copy of that
 * browser-generated key actually unseals. Every template's successor falls
 * back to 'regular' (Anketa::NEXT_CYCLE_TEMPLATE_KEY).
 */
test('a non-default meeting template reaches both sides, and its successor falls back to the regular template', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-template');
  const managerEmail = uniqueEmail('manager-template');
  const employeeToken = createActivationLink(employeeEmail);
  const managerToken = createActivationLink(managerEmail);

  const employee = await activate(browser, employeeToken);
  const manager = await activate(browser, managerToken);

  const anketaUrl = await createAnketa(
    employee,
    managerEmail,
    3,
    'Support & workload check-in',
  );

  // The employee's own side renders the support check-in question set, not
  // the regular one.
  const employeeMySide = employee.locator('.side-card').first();
  await expect(
    employeeMySide.getByRole('heading', {
      name: 'What would help protect your time?',
    }),
  ).toBeVisible();
  await expect(
    employeeMySide.getByRole('heading', { name: 'Feelings', exact: true }),
  ).toHaveCount(0);

  // Answer a template-only field and publish.
  const templateMarker = `E2E-TEMPLATE-MARKER-${Date.now()}`;
  await employeeMySide
    .locator('.block', { hasText: 'What would help protect your time?' })
    .locator('textarea')
    .fill(templateMarker);
  await employeeMySide.getByRole('button', { name: 'Publish' }).click();
  await expect(employeeMySide.getByText('Published')).toBeVisible();

  // Manager — a separate session — gets the manager half of the same
  // template on their own side, and the employee's answer decrypts under the
  // employee's template-specific question on the counterpart side.
  await manager.goto(anketaUrl);
  const managerMySide = manager.locator('.side-card').first();
  await expect(
    managerMySide.getByRole('heading', {
      name: "What I'm taking off your plate",
    }),
  ).toBeVisible();
  await expect(
    managerMySide.getByRole('heading', {
      name: 'How did the period go since the last meeting',
    }),
  ).toHaveCount(0);
  await expect(
    manager
      .locator('.side-card')
      .nth(1)
      .locator('.block', { hasText: 'What would help protect your time?' })
      .locator('.answer-text'),
  ).toHaveText(templateMarker);

  // Both participants' anketa lists label the open anketa with its meeting
  // type (GitHub issue #107).
  for (const page of [employee, manager]) {
    await page.goto('/');
    await expect(page.locator('.anketa-row')).toHaveCount(1);
    await expect(page.locator('.anketa-row')).toContainText(
      'Support & workload check-in',
    );
  }

  // Manager archives with the form's defaults — a next meeting gets created
  // (the date field is pre-filled from the pair's periodicity), with a next
  // anketa key generated and sealed for both sides by this browser.
  await manager.goto(anketaUrl);
  await expect(manager.locator('#next-meeting-date')).toBeVisible();
  await manager.getByRole('button', { name: 'Archive' }).click();
  await expect(manager.getByRole('button', { name: 'Archive' })).toHaveCount(0);

  // The employee's list now has the archived anketa plus its auto-created
  // successor. Only a still-open anketa carries a meeting-type label, and the
  // successor is 'regular', so none remains anywhere in the list.
  await employee.goto('/');
  const rows = employee.locator('.anketa-row');
  await expect(rows).toHaveCount(2);
  await expect(
    rows.filter({ has: employee.locator('.tag', { hasText: 'archived' }) }),
  ).toHaveCount(1);
  await expect(employee.locator('main')).not.toContainText(
    'Support & workload check-in',
  );
  const successorRow = rows.filter({
    hasNot: employee.locator('.tag', { hasText: 'archived' }),
  });
  await successorRow.click();
  await employee.waitForURL(/\/anketas\/[0-9a-f-]+$/);
  const successorUrl = employee.url();
  expect(successorUrl).not.toBe(anketaUrl);

  // The successor is back on the regular question set.
  const successorMySide = employee.locator('.side-card').first();
  await expect(
    successorMySide.getByRole('heading', { name: 'Feelings', exact: true }),
  ).toBeVisible();
  await expect(
    successorMySide.getByRole('heading', {
      name: 'What would help protect your time?',
    }),
  ).toHaveCount(0);

  // Employee publishes on the successor, and the manager — whose sealed copy
  // of the successor's key was produced by the manager's own browser at
  // archive time, but is unsealed here from a fresh page load — decrypts it.
  const successorMarker = `E2E-SUCCESSOR-MARKER-${Date.now()}`;
  await successorMySide.locator('textarea').first().fill(successorMarker);
  await successorMySide.getByRole('button', { name: 'Publish' }).click();
  await expect(successorMySide.getByText('Published')).toBeVisible();

  await manager.goto(successorUrl);
  await expect(
    manager.locator('.side-card').nth(1).locator('.answer-text').first(),
  ).toHaveText(successorMarker);
});

/**
 * Coverage for GitHub issue #111
 * (docs/decisions/2026-09-23-one-open-anketa-chain-per-pair.md): an anketa
 * created by hand while the pair already has an open one is a one-off — no
 * carry-forward into it, and archiving it never auto-creates a successor —
 * so the pair's chain can't fork. The server enforces all of it; this checks
 * the real UI shows it and that the chain really stays single end to end,
 * starting from a chain built the real way (archive-with-next carrying a goal
 * forward), not from seeded rows.
 */
test('an anketa created next to an open one is a one-off: no carry-forward and no successor', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-oneoff');
  const managerEmail = uniqueEmail('manager-oneoff');
  const employeeToken = createActivationLink(employeeEmail);
  const managerToken = createActivationLink(managerEmail);

  const employee = await activate(browser, employeeToken);
  // Only needs to exist as a real, keyed counterpart — the rest of this test
  // drives the employee alone.
  await activate(browser, managerToken);

  // Build a real chain: a regular anketa with a goal, archived with a next
  // meeting, so its successor gets the goal carried forward.
  const firstUrl = await createAnketa(employee, managerEmail, 3);
  const goalTitle = `E2E-GOAL-${Date.now()}`;
  await employee.getByPlaceholder('Goal title…').fill(goalTitle);
  await employee.getByRole('button', { name: 'Add goal' }).click();
  await expect(employee.locator('input[id^="goal-title-"]')).toHaveValue(
    goalTitle,
  );
  await employee.getByRole('button', { name: 'Archive' }).click();
  await expect(employee.getByRole('button', { name: 'Archive' })).toHaveCount(
    0,
  );

  await employee.goto('/');
  await expect(employee.locator('.anketa-row')).toHaveCount(2);
  const openRow = employee.locator('.anketa-row').filter({
    hasNot: employee.locator('.tag', { hasText: 'archived' }),
  });
  await openRow.click();
  await employee.waitForURL(/\/anketas\/[0-9a-f-]+$/);
  const chainUrl = employee.url();
  expect(chainUrl).not.toBe(firstUrl);
  await expect(employee.locator('input[id^="goal-title-"]')).toHaveValue(
    goalTitle,
  );

  // Now create a second, ad-hoc anketa for the same pair while the chain's
  // one is still open. The create form warns up front that it'll be a
  // one-off.
  await employee.goto('/anketas/new');
  await employee
    .getByPlaceholder('Type a name or email to search…')
    .fill(managerEmail);
  await employee.getByRole('button', { name: managerEmail }).click();
  await expect(
    employee.getByText('This pair already has an open anketa'),
  ).toBeVisible();
  const oneOffUrl = await createAnketa(
    employee,
    managerEmail,
    5,
    'Career growth',
  );
  expect([firstUrl, chainUrl]).not.toContain(oneOffUrl);

  // No carry-forward: the chain's goal wasn't copied in.
  await expect(employee.getByText('No goals yet.')).toBeVisible();
  await expect(employee.locator('input[id^="goal-title-"]')).toHaveCount(0);

  // The archive form explains there's no next meeting, and offers neither
  // the "skip" checkbox nor a next-meeting date.
  await expect(employee.getByText('This is a one-off anketa')).toBeVisible();
  await expect(
    employee.getByRole('checkbox', { name: "Don't create the next meeting" }),
  ).toHaveCount(0);
  await expect(employee.locator('#next-meeting-date')).toHaveCount(0);

  await employee.getByRole('button', { name: 'Archive' }).click();
  await expect(employee.getByRole('button', { name: 'Archive' })).toHaveCount(
    0,
  );

  // Archiving the one-off created nothing: still just the three anketas, and
  // the chain's own anketa is the pair's only open one, goal intact.
  await employee.goto('/');
  const rows = employee.locator('.anketa-row');
  await expect(rows).toHaveCount(3);
  const openRows = rows.filter({
    hasNot: employee.locator('.tag', { hasText: 'archived' }),
  });
  await expect(openRows).toHaveCount(1);
  await openRows.click();
  await employee.waitForURL(chainUrl);
  await expect(employee.locator('input[id^="goal-title-"]')).toHaveValue(
    goalTitle,
  );
});

/**
 * Waits until `page` shows the anketa as archived: the header's "archived"
 * tag, which only renders once the archive has been applied (the Archive
 * button merely turns into "Archiving…" while the request is in flight).
 */
async function expectArchived(page: Page): Promise<void> {
  await expect(
    page.locator('.tag', { hasText: /^archived$/ }).first(),
  ).toBeVisible();
}

/**
 * Clicks my own side's outer Save and holds the answers request in flight
 * until the returned function is called; that function lets it through and
 * waits for a successful response. The Save button reads "Saving…" by the time this
 * resolves.
 */
async function clickSaveAndHoldIt(
  mySide: Locator,
): Promise<() => Promise<void>> {
  const page = mySide.page();
  const pattern = '**/api/anketas/*/answers';
  let release: () => void = () => {};
  const released = new Promise<void>((resolve) => {
    release = resolve;
  });
  await page.route(pattern, async (route) => {
    await released;
    await route.continue();
  });
  await Promise.all([
    page.waitForRequest(pattern, { timeout: 10_000 }),
    mySide
      .locator('.answers-edit-actions')
      .getByRole('button', { name: 'Save' })
      .click(),
  ]);
  await expect(mySide.getByRole('button', { name: 'Saving…' })).toBeDisabled();
  return async () => {
    const responded = page.waitForResponse(pattern);
    release();
    const response = await responded;
    expect(response.ok()).toBe(true);
    await page.unroute(pattern);
  };
}

/**
 * GitHub issue #131/#134: the read-only view of an anketa side shows only what
 * was answered. An unanswered field renders nothing (no label, placeholder or
 * comment toggle), a block with nothing answered collapses to its title plus
 * one "No answer." line, and the generic input captions ("Entries", "Details",
 * "Anything to add?") are dropped above an answer. It applies to the
 * counterpart's side, to my own published side while not editing, and to both
 * sides once archived. Edit mode is unchanged. A field that was emptied after
 * being commented on stays visible, with its label, "No answer." and its thread.
 */
test('the read-only view hides unanswered fields and generic labels', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-collapse');
  const managerEmail = uniqueEmail('manager-collapse');
  const employeeToken = createActivationLink(employeeEmail);
  const managerToken = createActivationLink(managerEmail);

  const employee = await activate(browser, employeeToken);
  const manager = await activate(browser, managerToken);

  const anketaUrl = await createAnketa(employee, managerEmail, 3);

  // Only the mood notes are filled in.
  const notesMarker = `E2E-COLLAPSE-NOTES-${Date.now()}`;
  const employeeMySide = employee.locator('.side-card').first();
  await employeeMySide.locator('textarea').first().fill(notesMarker);
  await employeeMySide.getByRole('button', { name: 'Publish' }).click();
  await expect(employeeMySide.getByText('Published')).toBeVisible();

  // The same collapsing on the manager's counterpart side and on the
  // employee's own published side.
  await manager.goto(anketaUrl);
  const managerCounterpartSide = manager.locator('.side-card').nth(1);
  for (const side of [managerCounterpartSide, employeeMySide]) {
    const mood = questionBlock(side, 'Mood');
    await expect(mood.locator('.answer-text')).toHaveText(notesMarker);
    await expect(mood).not.toContainText('Anything to add?');
    // The unanswered mood radios are hidden entirely, not shown disabled.
    await expect(mood).not.toContainText('How are you feeling?');
    await expect(mood.locator('input[type=radio]')).toHaveCount(0);
    await expect(mood.locator('.block-empty')).toHaveCount(0);
    await expect(mood.locator('.thread')).toHaveCount(1);

    for (const [title, genericLabel] of [
      ['Feelings', 'Anything to add?'],
      ['Achievements', 'Entries'],
    ]) {
      const block = questionBlock(side, title);
      await expect(block.locator('.block-empty')).toHaveCount(1);
      await expect(block.locator('.block-empty')).toHaveText('No answer.');
      await expect(block).not.toContainText(genericLabel);
      await expect(block.locator('.field')).toHaveCount(0);
      await expect(block.locator('.thread')).toHaveCount(0);
    }
  }

  // Edit brings every prompt and input back; Cancel collapses again.
  await employeeMySide.getByRole('button', { name: 'Edit' }).click();
  const employeeFeelings = questionBlock(employeeMySide, 'Feelings');
  const employeeAchievements = questionBlock(employeeMySide, 'Achievements');
  await expect(employeeFeelings.locator('.block-empty')).toHaveCount(0);
  await expect(employeeFeelings).toContainText('Anything to add?');
  await expect(employeeAchievements).toContainText('Entries');
  await expect(
    employeeAchievements.getByPlaceholder('Add an entry…'),
  ).toBeVisible();
  await expect(
    questionBlock(employeeMySide, 'Mood').locator('input[type=radio]'),
  ).toHaveCount(6);
  await employeeMySide
    .locator('.answers-edit-actions')
    .getByRole('button', { name: 'Cancel' })
    .click();
  await expect(employeeFeelings.locator('.block-empty')).toHaveCount(1);
  await expect(employeeAchievements).not.toContainText('Entries');

  // Manager comments on the notes; the employee then empties them. The field
  // stays on the manager's already-open tab (via the live poll, no reload),
  // now with its generic label, "No answer." and the thread.
  const managerMood = questionBlock(managerCounterpartSide, 'Mood');
  const managerThread = managerMood.locator('.thread');
  await managerThread.getByRole('button', { name: /comment/i }).click();
  await managerThread.locator('input[type=text]').fill('tell me more');
  await managerThread.getByRole('button', { name: 'Post' }).click();
  await expect(managerThread.getByText('tell me more')).toBeVisible();

  await employee.reload();
  await employeeMySide.getByRole('button', { name: 'Edit' }).click();
  await employeeMySide.locator('textarea').first().fill('');
  // The save is held in flight: the side is readonly then, but must keep the
  // edit-mode layout rather than collapsing (and re-expanding if it failed).
  const releaseSave = await clickSaveAndHoldIt(employeeMySide);
  await expect(employeeFeelings).toContainText('Anything to add?');
  await expect(employeeFeelings.locator('.block-empty')).toHaveCount(0);
  // ...and today's disabled radios and pills, not the chosen-options view
  // (#135).
  await expect(
    questionBlock(employeeMySide, 'Mood').locator('input[type=radio]'),
  ).toHaveCount(6);
  await expect(employeeFeelings.locator('button.pill')).toHaveCount(12);
  await expect(employeeFeelings.locator('.field-empty')).toHaveCount(0);
  await expect(employeeAchievements).toContainText('Entries');
  await releaseSave();
  await expect(employeeMySide.getByText('Published')).toBeVisible();
  await expect(employeeFeelings.locator('.block-empty')).toHaveCount(1);

  await expect(managerMood.locator('.answer-text')).toHaveCount(0, {
    timeout: 8000,
  });
  await expect(managerMood).toContainText('Anything to add?');
  await expect(managerMood.locator('.field-empty')).toHaveText('No answer.');
  await expect(managerMood.locator('.block-empty')).toHaveCount(0);
  await expect(managerThread.getByText('tell me more')).toBeVisible();

  // The manager publishes only the period summary (its generic "Details"
  // caption dropped on both participants' view), then archives: editing is
  // no longer offered. The archived-but-never-published side is covered by
  // the next test.
  const managerMarker = `E2E-COLLAPSE-MANAGER-${Date.now()}`;
  const managerMySide = manager.locator('.side-card').first();
  await managerMySide.locator('textarea').first().fill(managerMarker);
  await managerMySide.getByRole('button', { name: 'Publish' }).click();
  await expect(managerMySide.getByText('Published')).toBeVisible();
  await manager
    .getByRole('checkbox', { name: "Don't create the next meeting" })
    .check({ force: true });
  await manager.getByRole('button', { name: 'Archive' }).click();
  await expectArchived(manager);
  await expect(
    questionBlock(managerCounterpartSide, 'Feelings').locator('.block-empty'),
  ).toHaveCount(1);

  await employee.reload();
  await expectArchived(employee);
  await expect(
    questionBlock(employeeMySide, 'Feelings').locator('.block-empty'),
  ).toHaveCount(1);
  await expect(employeeMySide.getByText('Published')).toBeVisible();
  await expect(
    employee.getByRole('button', { name: 'Edit', exact: true }),
  ).toHaveCount(0);
  for (const side of [managerMySide, employee.locator('.side-card').nth(1)]) {
    await expect(side.locator('textarea')).toHaveCount(0);
    // Only the period summary's text, with its generic "Details" caption
    // dropped; every other manager block is "No answer.".
    await expect(side.locator('.field')).toHaveCount(1);
    await expect(side.locator('.answer-text')).toHaveText(managerMarker);
    const periodSummary = questionBlock(
      side,
      'How did the period go since the last meeting',
    );
    await expect(periodSummary).not.toContainText('Details');
    await expect(periodSummary.locator('.block-empty')).toHaveCount(0);
    await expect(side.locator('.block-empty')).toHaveCount(
      (await side.locator('.block').count()) - 1,
    );
  }
});

/**
 * GitHub issue #131/#134: only the three generic captions are dropped. A real
 * sub-prompt — here the support template's commitments hint — keeps showing
 * above its answer on the counterpart's side.
 */
test('the read-only view keeps real sub-prompt labels above an answer', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-subprompt');
  const managerEmail = uniqueEmail('manager-subprompt');
  const employeeToken = createActivationLink(employeeEmail);
  const managerToken = createActivationLink(managerEmail);

  const employee = await activate(browser, employeeToken);
  const manager = await activate(browser, managerToken);

  const anketaUrl = await createAnketa(
    employee,
    managerEmail,
    3,
    'Support & workload check-in',
  );

  await manager.goto(anketaUrl);
  const managerMySide = manager.locator('.side-card').first();
  const commitmentText = `E2E-COMMITMENT-${Date.now()}`;
  const commitments = questionBlock(
    managerMySide,
    "What I'm taking off your plate",
  );
  await commitments.getByPlaceholder('Add an entry…').fill(commitmentText);
  await commitments.getByRole('button', { name: 'Add' }).click();
  await expect(commitments.locator('.entry-text')).toHaveText(commitmentText);
  await managerMySide.getByRole('button', { name: 'Publish' }).click();
  await expect(managerMySide.getByText('Published')).toBeVisible();

  await employee.goto(anketaUrl);
  const employeeCommitments = questionBlock(
    employee.locator('.side-card').nth(1),
    "What I'm taking off your plate",
  );
  await expect(employeeCommitments.locator('.label')).toHaveText(
    "What's changing, and by when (the date shown is when the entry was added)",
  );
  await expect(employeeCommitments.locator('.entry-text')).toHaveText(
    commitmentText,
  );

  // A side that was never published is collapsed too once archived: every
  // block of the employee's untouched draft is just "No answer.".
  await manager
    .getByRole('checkbox', { name: "Don't create the next meeting" })
    .check({ force: true });
  await manager.getByRole('button', { name: 'Archive' }).click();
  await expectArchived(manager);
  await employee.reload();
  await expectArchived(employee);
  const employeeMySide = employee.locator('.side-card').first();
  await expect(employeeMySide.getByText('archived')).toBeVisible();
  await expect(employeeMySide.locator('textarea')).toHaveCount(0);
  await expect(employeeMySide.locator('.field')).toHaveCount(0);
  // The support template's employee side has six question blocks.
  await expect(employeeMySide.locator('.block')).toHaveCount(6);
  await expect(employeeMySide.locator('.block-empty')).toHaveCount(6);
});

/**
 * GitHub issue #135 (part 2 of #131): in the collapsed read-only view a radio
 * answer shows its chosen option as text, and a checkbox answer only its
 * chosen options as a non-interactive list, instead of the full list of
 * disabled options. Edit mode still shows every option.
 */
test('the read-only view shows only the chosen radio and checkbox options', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-choices');
  const managerEmail = uniqueEmail('manager-choices');
  const employeeToken = createActivationLink(employeeEmail);
  const managerToken = createActivationLink(managerEmail);

  const employee = await activate(browser, employeeToken);
  const manager = await activate(browser, managerToken);

  const anketaUrl = await createAnketa(employee, managerEmail, 3);

  const employeeMySide = employee.locator('.side-card').first();
  // The wrapping <label> is clicked, as a real user would: the native radio
  // is visually hidden behind the custom `.dot` (components.css's .radio).
  await questionBlock(employeeMySide, 'Mood')
    .locator('label.radio', { hasText: 'Good' })
    .click();
  const feelings = questionBlock(employeeMySide, 'Feelings');
  // Calm first: the list below must follow option order, where Proud comes
  // first, not click order.
  await feelings.getByRole('button', { name: 'Calm' }).click();
  await feelings.getByRole('button', { name: 'Proud' }).click();
  await expect(feelings.getByRole('button', { name: 'Proud' })).toHaveAttribute(
    'aria-pressed',
    'true',
  );
  await employeeMySide.getByRole('button', { name: 'Publish' }).click();
  await expect(employeeMySide.getByText('Published')).toBeVisible();

  await manager.goto(anketaUrl);
  const managerCounterpartSide = manager.locator('.side-card').nth(1);
  for (const side of [managerCounterpartSide, employeeMySide]) {
    const mood = questionBlock(side, 'Mood');
    await expect(mood).toContainText('How are you feeling?');
    await expect(mood.locator('.answer-choice')).toHaveText('Good');

    // A list under its field label, not loose text.
    const feelingsBlock = questionBlock(side, 'Feelings');
    await expect(feelingsBlock).toContainText('Which of these apply?');
    const chosen = feelingsBlock.getByRole('list').getByRole('listitem');
    // Option order, not click order: "Proud" comes before "Calm".
    await expect(chosen).toHaveText(['Proud', 'Calm']);

    // Checked only once the answers above have rendered.
    await expect(side.locator('input[type=radio]')).toHaveCount(0);
    await expect(side.locator('button.pill')).toHaveCount(0);
  }

  // Edit brings back every option, with the chosen ones selected. The mood
  // is changed to Bad and saved, and the Save held in flight keeps every
  // option shown and selected (disabled) rather than flipping the answered
  // choices to text and back.
  await employeeMySide.getByRole('button', { name: 'Edit' }).click();
  const employeeMood = questionBlock(employeeMySide, 'Mood');
  await expect(employeeMood.getByRole('radio', { name: 'Good' })).toBeChecked();
  await employeeMood.locator('label.radio', { hasText: 'Bad' }).click();
  const moodBad = employeeMood.getByRole('radio', { name: 'Bad' });
  const expectEveryOptionShown = async () => {
    await expect(employeeMood.locator('input[type=radio]')).toHaveCount(6);
    await expect(moodBad).toBeChecked();
    await expect(feelings.locator('button.pill')).toHaveCount(12);
    await expect(
      feelings.locator('button.pill[aria-pressed="true"]'),
    ).toHaveText(['Proud', 'Calm']);
    await expect(employeeMySide.locator('.answer-choice')).toHaveCount(0);
    await expect(feelings.locator('.answer-choices')).toHaveCount(0);
  };
  await expectEveryOptionShown();
  await expect(moodBad).toBeEnabled();

  const releaseSave = await clickSaveAndHoldIt(employeeMySide);
  await expectEveryOptionShown();
  await expect(moodBad).toBeDisabled();
  await expect(
    feelings.locator('button.pill[aria-pressed="true"]').first(),
  ).toBeDisabled();
  await releaseSave();
  await expect(employeeMySide.getByText('Published')).toBeVisible();
  await expect(employeeMood.locator('.answer-choice')).toHaveText('Bad');
  // The manager's already-open tab picks up the new choice via the live poll.
  await expect(
    questionBlock(managerCounterpartSide, 'Mood').locator('.answer-choice'),
  ).toHaveText('Bad', { timeout: 8000 });

  // Archived: still only the chosen options, from both participants' view.
  await manager
    .getByRole('checkbox', { name: "Don't create the next meeting" })
    .check({ force: true });
  await manager.getByRole('button', { name: 'Archive' }).click();
  await expectArchived(manager);
  await employee.reload();
  await expectArchived(employee);
  await expect(
    employee.getByRole('button', { name: 'Edit', exact: true }),
  ).toHaveCount(0);
  for (const side of [managerCounterpartSide, employeeMySide]) {
    await expect(
      questionBlock(side, 'Mood').locator('.answer-choice'),
    ).toHaveText('Bad');
    await expect(
      questionBlock(side, 'Feelings').getByRole('listitem'),
    ).toHaveText(['Proud', 'Calm']);
    await expect(side.locator('input[type=radio]')).toHaveCount(0);
    await expect(side.locator('button.pill')).toHaveCount(0);
  }
});

/**
 * GitHub issue #149: every comment action driven from the keyboard keeps
 * focus somewhere useful instead of dropping it to <body>, which every one of
 * them did before (each swaps out or removes the button that had focus, and
 * Chromium blurs a button as soon as it's disabled for the request). Deleting
 * the last comment on an emptied field hides the field with its thread, so
 * focus goes to the question block's heading.
 */
test('comment actions keep keyboard focus, down to a hidden field', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-focus');
  const managerEmail = uniqueEmail('manager-focus');
  const employee = await activate(browser, createActivationLink(employeeEmail));
  const manager = await activate(browser, createActivationLink(managerEmail));
  const anketaUrl = await createAnketa(employee, managerEmail, 3);

  const employeeMySide = employee.locator('.side-card').first();
  await employeeMySide.locator('textarea').first().fill('Busy but fine');
  await employeeMySide.getByRole('button', { name: 'Publish' }).click();
  await expect(employeeMySide.getByText('Published')).toBeVisible();

  await manager.goto(anketaUrl);
  const managerCounterpartSide = manager.locator('.side-card').nth(1);
  const mood = questionBlock(managerCounterpartSide, 'Mood');
  const thread = moodNotesThread(managerCounterpartSide);
  const press = async (button: Locator, key = 'Enter') => {
    await button.focus();
    await manager.keyboard.press(key);
  };

  // Posting from the keyboard keeps focus in the comment input.
  await press(thread.getByRole('button', { name: 'Comment' }));
  const input = thread.getByPlaceholder('Add a comment…');
  for (const text of ['first', 'second']) {
    await input.fill(text);
    await press(thread.getByRole('button', { name: 'Post' }));
    await expect(thread.getByText(text)).toBeVisible();
    await expect(input).toBeFocused();
  }

  // Edit: into the edit input, then back to the comment's own Edit button,
  // on Cancel and on Save alike.
  // While editing, the text lives in the input's value, not in the row's
  // text, so the open edit row is found by its input instead.
  const second = thread.locator('.comment', { hasText: 'second' });
  const editRow = thread.locator('.comment', { has: manager.locator('input') });
  await press(second.getByRole('button', { name: 'Edit' }));
  const editInput = editRow.locator('input[type=text]');
  await expect(editInput).toBeFocused();
  await press(editRow.getByRole('button', { name: 'Cancel' }));
  await expect(second.getByRole('button', { name: 'Edit' })).toBeFocused();

  // An Enter held on Edit opens the edit once; its auto-repeats, landing in
  // the edit input, don't submit it.
  await holdEnterOnEdit(
    manager,
    second.getByRole('button', { name: 'Edit' }),
    editInput,
  );
  await press(editRow.getByRole('button', { name: 'Cancel' }));
  await expect(second.getByRole('button', { name: 'Edit' })).toBeFocused();

  await press(second.getByRole('button', { name: 'Edit' }));
  await editInput.fill('second, edited');
  await manager.keyboard.press('Enter');
  const edited = thread.locator('.comment', { hasText: 'second, edited' });
  await expect(edited.getByRole('button', { name: 'Edit' })).toBeFocused();

  // Delete: the safe Cancel first, and back to Delete on Cancel.
  await press(edited.getByRole('button', { name: 'Delete' }));
  await expect(edited.getByRole('button', { name: 'Cancel' })).toBeFocused();
  await manager.keyboard.press('Enter');
  await expect(edited.getByRole('button', { name: 'Delete' })).toBeFocused();

  // A failed delete keeps focus on Confirm delete, for another try.
  const commentsPattern = '**/api/anketas/*/comments';
  await manager.route(commentsPattern, (route) =>
    route.request().method() === 'PUT'
      ? route.fulfill({ status: 500, body: '{}' })
      : route.continue(),
  );
  await press(edited.getByRole('button', { name: 'Delete' }));
  await manager.keyboard.press('Shift+Tab');
  const confirm = edited.getByRole('button', { name: 'Confirm delete' });
  await expect(confirm).toBeFocused();
  await manager.keyboard.press('Enter');
  await expect(thread.getByRole('alert')).toBeVisible();
  await expect(confirm).toBeFocused();
  await manager.unroute(commentsPattern);

  // A delete that leaves the field shown lands on the thread's toggle.
  await manager.keyboard.press('Enter');
  await expect(thread.getByText('second, edited')).toHaveCount(0);
  const toggle = thread.getByRole('button', { name: '1 comment' });
  await expect(toggle).toBeFocused();

  // A slow request never pulls focus back from where the user moved on to
  // meanwhile, even within the same thread.
  const firstComment = thread.locator('.comment', { hasText: 'first' });
  await press(firstComment.getByRole('button', { name: 'Edit' }));
  await editInput.fill('first, edited');
  let release: () => void = () => {};
  const released = new Promise<void>((resolve) => {
    release = resolve;
  });
  await manager.route(commentsPattern, async (route) => {
    if (route.request().method() === 'PUT') await released;
    await route.continue();
  });
  await Promise.all([
    manager.waitForRequest(
      (request) =>
        request.method() === 'PUT' && request.url().endsWith('/comments'),
    ),
    manager.keyboard.press('Enter'),
  ]);
  await input.focus();
  const saved = manager.waitForResponse(
    (response) =>
      response.request().method() === 'PUT' &&
      response.url().endsWith('/comments'),
  );
  release();
  expect((await saved).ok()).toBe(true);
  await manager.unroute(commentsPattern);
  await expect(thread.getByText('first, edited')).toBeVisible();
  await expect(input).toBeFocused();

  // The employee empties the notes; the field stays on the manager's tab
  // (via the live poll) only because of its remaining comment.
  await employeeMySide.getByRole('button', { name: 'Edit' }).click();
  await employeeMySide.locator('textarea').first().fill('');
  await employeeMySide.getByRole('button', { name: 'Save' }).click();
  await expect(employeeMySide.getByText('Published')).toBeVisible();
  await expect(mood.locator('.field-empty')).toHaveText('No answer.', {
    timeout: 8000,
  });

  // Deleting that last comment hides the field and its thread: focus goes
  // to the block's heading.
  const first = mood.locator('.comment', { hasText: 'first' });
  await press(first.getByRole('button', { name: 'Delete' }));
  await manager.keyboard.press('Shift+Tab');
  await expect(
    first.getByRole('button', { name: 'Confirm delete' }),
  ).toBeFocused();
  await manager.keyboard.press('Enter');
  await expect(mood.locator('.thread')).toHaveCount(0);
  await expect(mood.locator('.block-empty')).toHaveText('No answer.');
  await expect(mood.getByRole('heading', { name: 'Mood' })).toBeFocused();
});

/**
 * GitHub issue #151, the #149 approach applied to outcomes and list-answer
 * entries: every Edit/Delete/Remove/Save/Cancel driven from the keyboard keeps
 * focus somewhere useful instead of dropping it to <body>. A deleted row hands
 * focus to a heading.
 */
test('outcome and list entry actions keep keyboard focus', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-rowfocus');
  const managerEmail = uniqueEmail('manager-rowfocus');
  const employee = await activate(browser, createActivationLink(employeeEmail));
  await activate(browser, createActivationLink(managerEmail));
  await createAnketa(employee, managerEmail, 3);
  const press = async (button: Locator, key = 'Enter') => {
    await button.focus();
    await employee.keyboard.press(key);
  };

  // Outcomes. Adding with Enter keeps focus in the add input, which is
  // disabled while the request runs.
  const outcomes = employee.locator('section.card', {
    has: employee.getByRole('heading', { name: 'Meeting outcomes' }),
  });
  const addOutcome = outcomes.getByPlaceholder('Add an outcome…');
  for (const text of ['first outcome', 'second outcome']) {
    await addOutcome.fill(text);
    await addOutcome.press('Enter');
    await expect(outcomes.getByText(text)).toBeVisible();
    await expect(addOutcome).toBeFocused();
  }

  // A failed add keeps focus in the add input too, with the text kept.
  const outcomesPattern = '**/api/anketas/*/outcomes';
  await employee.route(outcomesPattern, (route) =>
    route.request().method() === 'PUT'
      ? route.fulfill({ status: 500, body: '{}' })
      : route.continue(),
  );
  await addOutcome.fill('third outcome');
  await addOutcome.press('Enter');
  // The add error is a page-level banner, outside the outcomes card, carrying
  // the routed 500's status text.
  await expect(
    employee.getByRole('alert').filter({ hasText: 'Internal Server Error' }),
  ).toBeVisible();
  await expect(addOutcome).toHaveValue('third outcome');
  await expect(addOutcome).toBeFocused();
  await employee.unroute(outcomesPattern);
  await addOutcome.fill('');

  // Edit: into the edit input, then back to the row's own Edit button, on
  // Cancel and on Save alike. While editing, the text lives in the input's
  // value, so the open edit row is found by its input instead.
  const second = outcomes.locator('.outcome-entry', {
    hasText: 'second outcome',
  });
  const editRow = outcomes.locator('.outcome-entry', {
    has: employee.locator('input[type=text]'),
  });
  const outcomeEditInput = editRow.locator('input[type=text]');
  await press(second.getByRole('button', { name: 'Edit' }));
  await expect(outcomeEditInput).toBeFocused();
  await press(editRow.getByRole('button', { name: 'Cancel' }));
  await expect(second.getByRole('button', { name: 'Edit' })).toBeFocused();
  // An Enter held on Edit opens the edit once; its auto-repeats, landing in
  // the edit input, don't submit it.
  await holdEnterOnEdit(
    employee,
    second.getByRole('button', { name: 'Edit' }),
    outcomeEditInput,
  );
  await press(editRow.getByRole('button', { name: 'Cancel' }));
  await expect(second.getByRole('button', { name: 'Edit' })).toBeFocused();
  await press(second.getByRole('button', { name: 'Edit' }));
  await outcomeEditInput.fill('second outcome, edited');
  await employee.keyboard.press('Enter');
  const edited = outcomes.locator('.outcome-entry', {
    hasText: 'second outcome, edited',
  });
  await expect(edited.getByRole('button', { name: 'Edit' })).toBeFocused();

  // A double-click's second click on Confirm delete doesn't confirm. Which
  // button ends up under a double-click on Delete depends on the label
  // widths, so the second click (detail 2) is dispatched on Confirm directly.
  const deletePuts: string[] = [];
  const onDeletePut = (request: { method(): string; url(): string }) => {
    if (request.method() === 'PUT' && request.url().endsWith('/outcomes')) {
      deletePuts.push(request.url());
    }
  };
  employee.on('request', onDeletePut);
  await edited.getByRole('button', { name: 'Delete' }).click();
  const confirmOutcome = edited.getByRole('button', { name: 'Confirm delete' });
  await confirmOutcome.dispatchEvent('click', { detail: 2 });
  await expect(confirmOutcome).toBeVisible();
  await press(edited.getByRole('button', { name: 'Cancel' }));
  employee.off('request', onDeletePut);
  expect(deletePuts).toEqual([]);

  // Delete: the safe Cancel first, and back to Delete on Cancel.
  await press(edited.getByRole('button', { name: 'Delete' }));
  await expect(edited.getByRole('button', { name: 'Cancel' })).toBeFocused();
  await employee.keyboard.press('Enter');
  await expect(edited.getByRole('button', { name: 'Delete' })).toBeFocused();

  // A failed delete keeps focus on Confirm delete, for another try.
  await employee.route(outcomesPattern, (route) =>
    route.request().method() === 'PUT'
      ? route.fulfill({ status: 500, body: '{}' })
      : route.continue(),
  );
  await press(edited.getByRole('button', { name: 'Delete' }));
  await employee.keyboard.press('Shift+Tab');
  const confirm = edited.getByRole('button', { name: 'Confirm delete' });
  await expect(confirm).toBeFocused();
  await employee.keyboard.press('Enter');
  await expect(outcomes.getByRole('alert')).toBeVisible();
  await expect(confirm).toBeFocused();
  await employee.unroute(outcomesPattern);

  // A successful delete takes the row with it: focus goes to the heading.
  await employee.keyboard.press('Enter');
  await expect(outcomes.getByText('second outcome, edited')).toHaveCount(0);
  await expect(
    outcomes.getByRole('heading', { name: 'Meeting outcomes' }),
  ).toBeFocused();

  // A slow save never pulls focus back from where the user moved on to
  // meanwhile, even within the same card.
  const first = outcomes.locator('.outcome-entry', {
    hasText: 'first outcome',
  });
  await press(first.getByRole('button', { name: 'Edit' }));
  await outcomeEditInput.fill('first outcome, edited');
  let release: () => void = () => {};
  const released = new Promise<void>((resolve) => {
    release = resolve;
  });
  await employee.route(outcomesPattern, async (route) => {
    if (route.request().method() === 'PUT') await released;
    await route.continue();
  });
  await Promise.all([
    employee.waitForRequest(
      (request) =>
        request.method() === 'PUT' && request.url().endsWith('/outcomes'),
    ),
    employee.keyboard.press('Enter'),
  ]);
  await addOutcome.focus();
  const saved = employee.waitForResponse(
    (response) =>
      response.request().method() === 'PUT' &&
      response.url().endsWith('/outcomes'),
  );
  release();
  expect((await saved).ok()).toBe(true);
  await employee.unroute(outcomesPattern);
  await expect(outcomes.getByText('first outcome, edited')).toBeVisible();
  await expect(addOutcome).toBeFocused();

  // List-answer entries on my own editable side.
  const achievements = employee
    .locator('.side-card')
    .first()
    .locator('.block', { hasText: 'Achievements' });
  const addEntry = achievements.getByPlaceholder('Add an entry…');
  for (const text of ['first entry', 'second entry']) {
    await addEntry.fill(text);
    await addEntry.press('Enter');
    await expect(achievements.getByText(text)).toBeVisible();
  }
  const secondEntry = achievements.locator('.entry', {
    hasText: 'second entry',
  });
  const entryEditRow = achievements.locator('.entry', {
    has: employee.locator('.entry-edit-input'),
  });
  const entryEditInput = entryEditRow.locator('.entry-edit-input');

  // Edit → the input; Cancel, Escape and Save → back to the entry's Edit.
  await press(secondEntry.getByRole('button', { name: 'Edit' }));
  await expect(entryEditInput).toBeFocused();
  await press(entryEditRow.getByRole('button', { name: 'Cancel' }));
  await expect(secondEntry.getByRole('button', { name: 'Edit' })).toBeFocused();
  await employee.keyboard.press('Enter');
  await expect(entryEditInput).toBeFocused();
  await employee.keyboard.press('Escape');
  await expect(secondEntry.getByRole('button', { name: 'Edit' })).toBeFocused();
  await employee.keyboard.press('Enter');
  await entryEditInput.fill('second entry, edited');
  await employee.keyboard.press('Enter');
  const editedEntry = achievements.locator('.entry', {
    hasText: 'second entry, edited',
  });
  await expect(editedEntry.getByRole('button', { name: 'Edit' })).toBeFocused();
  // Saving with the Save button rather than Enter, with a real change.
  await employee.keyboard.press('Enter');
  await expect(entryEditInput).toBeFocused();
  await entryEditInput.fill('second entry, edited again');
  await press(entryEditRow.getByRole('button', { name: 'Save' }));
  const reEditedEntry = achievements.locator('.entry', {
    hasText: 'second entry, edited again',
  });
  await expect(
    reEditedEntry.getByRole('button', { name: 'Edit' }),
  ).toBeFocused();

  // An Enter held on Edit opens the edit once; its auto-repeats, landing in
  // the edit input, don't save it.
  await holdEnterOnEdit(
    employee,
    reEditedEntry.getByRole('button', { name: 'Edit' }),
    entryEditInput,
  );
  await employee.keyboard.press('Escape');
  await expect(
    reEditedEntry.getByRole('button', { name: 'Edit' }),
  ).toBeFocused();

  // A double-click's second click (detail 2) on Remove doesn't remove: the
  // next entry's Remove can render where the first click landed.
  await reEditedEntry
    .getByRole('button', { name: 'Remove' })
    .dispatchEvent('click', { detail: 2 });
  await expect(reEditedEntry).toBeVisible();

  // Remove takes the row with it: focus goes to the block's heading.
  await press(reEditedEntry.getByRole('button', { name: 'Remove' }));
  await expect(achievements.getByText('second entry, edited')).toHaveCount(0);
  await expect(
    achievements.getByRole('heading', { name: 'Achievements' }),
  ).toBeFocused();

  // Text typed into a comment input while its post is in flight is kept,
  // not cleared along with the posted text.
  const outcomeThread = outcomes
    .locator('.outcome-item', { hasText: 'first outcome, edited' })
    .locator('.thread');
  await outcomeThread.getByRole('button', { name: 'Comment' }).click();
  const commentInput = outcomeThread.getByPlaceholder('Add a comment…');
  await commentInput.fill('posted');
  let releasePost: () => void = () => {};
  const postHeld = new Promise<void>((resolve) => {
    releasePost = resolve;
  });
  const commentsPattern = '**/api/anketas/*/comments';
  await employee.route(commentsPattern, async (route) => {
    if (route.request().method() === 'PUT') await postHeld;
    await route.continue();
  });
  await Promise.all([
    employee.waitForRequest(
      (request) =>
        request.method() === 'PUT' && request.url().endsWith('/comments'),
    ),
    commentInput.press('Enter'),
  ]);
  await commentInput.fill('typed meanwhile');
  const posted = employee.waitForResponse(
    (response) =>
      response.request().method() === 'PUT' &&
      response.url().endsWith('/comments'),
  );
  releasePost();
  expect((await posted).ok()).toBe(true);
  await employee.unroute(commentsPattern);
  await expect(outcomeThread.getByText('posted')).toBeVisible();
  await expect(commentInput).toHaveValue('typed meanwhile');
});
