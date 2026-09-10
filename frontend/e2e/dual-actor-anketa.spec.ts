import { test, expect, type Browser, type Page } from '@playwright/test';
import { createActivationLink, uniqueEmail } from './helpers/provision.js';

const PASSWORD = 'correct horse battery staple 123';

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
  // published/readonly text answer renders as a plain paragraph
  // (AnswerField.svelte's `.answer-text`), not a <textarea> — only the
  // editing state (my own unpublished/being-edited side, above) still uses
  // a real <textarea>.
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

  // Manager comments on the employee's marked field (still visible on the
  // counterpart side after publishing their own side).
  const managerThread = managerCounterpartSide.locator('.thread').first();
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

  const employeeThread = employee
    .locator('.side-card')
    .first()
    .locator('.thread')
    .first();
  await employeeThread.getByRole('button', { name: /comment/i }).click();
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
