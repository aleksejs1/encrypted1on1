import {
  test,
  expect,
  type Browser,
  type Locator,
  type Page,
  type Route,
} from '@playwright/test';
import {
  archive,
  archiveButton,
  confirmArchiveButton,
} from './helpers/archive.js';
import {
  createActivationLink,
  setFormVersion,
  uniqueEmail,
} from './helpers/provision.js';

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

/**
 * My side's answers-edit Edit/Save/Cancel. They're both at the card's bottom
 * and at its top (the header's Edit, then the sticky edit bar, GitHub issue
 * #166), so a role-and-name lookup matches two buttons.
 */
function answersEditButton(
  mySide: Locator,
  action: 'edit' | 'save' | 'cancel',
  place: 'top' | 'bottom' = 'bottom',
): Locator {
  // At the top, Edit is in the card's header and Save/Cancel in the bar.
  const region =
    place === 'bottom' ? 'bottom' : action === 'edit' ? 'header' : 'bar';
  return mySide.locator(
    `[data-answers-edit="${region}"] [data-action="${action}-answers"]`,
  );
}

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
  //
  // A new account's empty list explains the first step (GitHub issue #197),
  // and its button is the way into the create form.
  const firstStep = employee.getByRole('link', {
    name: 'Start your first 1:1',
  });
  await expect(employee.getByText('Jot down topics')).toBeVisible();
  await firstStep.click();
  await employee.waitForURL('/anketas/new');
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
  // DateInput only parses on blur (commitText()) — the "Create 1:1"
  // button starts out disabled, and a disabled button can't take focus to
  // blur this field for us, so it must be done explicitly first.
  await meetingDateInput.blur();
  // No role is preselected (GitHub issues #198, #251), and the form can't be
  // submitted without one.
  const employeeRole = employee.getByRole('radio', {
    name: 'leads this 1:1',
  });
  const createButton = employee.getByRole('button', { name: 'Create 1:1' });
  await expect(employeeRole).not.toBeChecked();
  await expect(
    employee.getByRole('radio', { name: 'I lead this 1:1' }),
  ).not.toBeChecked();
  await expect(createButton).toBeDisabled();
  await employee.locator('label.radio', { hasText: 'leads this 1:1' }).click();
  await createButton.click();
  await employee.waitForURL(/\/anketas\/[0-9a-f-]+$/);
  const anketaUrl = employee.url();

  // "Create another" reopens the form with colleague and date empty again,
  // and no role: it's chosen for each colleague (GitHub issue #251).
  await employee
    .getByRole('button', {
      name: 'Create another 1:1 with the same settings',
    })
    .click();
  await employee.waitForURL('/anketas/new');
  await expect(employeeRole).not.toBeChecked();
  await expect(counterpartInput).toHaveValue('');
  await expect(meetingDateInput).toHaveValue('');
  await expect(createButton).toBeDisabled();

  // The first-step card is for an empty list only.
  await employee.goto('/');
  await expect(employee.locator('.anketa-row')).toHaveCount(1);
  await expect(firstStep).toHaveCount(0);
  await employee.goto(anketaUrl);

  // Employee publishes their side with a unique marker.
  const employeeMarker = `E2E-MARKER-EMPLOYEE-${Date.now()}`;
  const employeeMySide = employee.locator('.side-card').first();
  // An unpublished draft says nothing is required (GitHub issue #199); the
  // line goes once the side is published.
  const optionalHint = employee.getByText(
    'All fields are optional. A couple of topics to talk about is enough.',
  );
  await expect(employeeMySide.locator(optionalHint)).toBeVisible();
  await employeeMySide.locator('textarea').first().fill(employeeMarker);
  await employeeMySide.getByRole('button', { name: 'Publish' }).click();
  await expect(employeeMySide.getByText('Published')).toBeVisible();
  await expect(optionalHint).toHaveCount(0);

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
  // Started from the card's header and saved with Ctrl+S (GitHub issue
  // #166): the sticky edit bar stays in view at the card's bottom, says
  // there are unsaved changes, and closing the tab meanwhile warns.
  const employeeEditedMarker = `E2E-MARKER-EMPLOYEE-EDITED-${Date.now()}`;
  await answersEditButton(employeeMySide, 'edit', 'top').click();
  const editBar = employeeMySide.locator('.answers-edit-bar');
  await expect(
    answersEditButton(employeeMySide, 'cancel', 'top'),
  ).toBeFocused();
  await expect(editBar).not.toContainText('Unsaved changes');
  const firstAnswer = employeeMySide.locator('textarea').first();
  // With an emoji shortcode (GitHub issue #240), stored as typed.
  await firstAnswer.fill(`${employeeEditedMarker} :tada:`);
  await expect(editBar).toContainText('Unsaved changes');
  await answersEditButton(employeeMySide, 'save').scrollIntoViewIfNeeded();
  await expect(editBar).toBeInViewport();
  const unloadDialog = employee.waitForEvent('dialog');
  await employee.close({ runBeforeUnload: true });
  const dialog = await unloadDialog;
  expect(dialog.type()).toBe('beforeunload');
  await dialog.dismiss();
  // By its title: the textarea is gone once saved.
  const firstAnswerTitle = await employeeMySide
    .locator('.block', { has: employee.locator('textarea') })
    .first()
    .locator('h4')
    .textContent();
  const firstAnswerHeading = questionBlock(
    employeeMySide,
    (firstAnswerTitle ?? '').trim(),
  ).locator('h4');
  await firstAnswer.press('ControlOrMeta+s');
  await expect(editBar).toHaveCount(0);
  await expect(firstAnswerHeading).toBeFocused();
  await expect(employeeMySide.getByText('Published')).toBeVisible();

  // Manager — a separate session — reloads and sees the edited content, not the
  // original marker: the edit genuinely round-tripped through the server,
  // re-encrypted under the shared anketa key, not merely updated in local state.
  // The shortcode shows as the emoji.
  await manager.reload();
  await expect(
    managerCounterpartSide.locator('.answer-text').first(),
  ).toHaveText(`${employeeEditedMarker} 🎉`);

  // Edit reopens the text as typed, shortcode included.
  await answersEditButton(employeeMySide, 'edit', 'top').click();
  await expect(employeeMySide.locator('textarea').first()).toHaveValue(
    `${employeeEditedMarker} :tada:`,
  );
  await answersEditButton(employeeMySide, 'cancel', 'top').click();
  await expect(editBar).toHaveCount(0);

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
  await managerThread.locator('input[type=text]').fill('looks **good** to me');
  await managerThread.getByRole('button', { name: 'Post' }).click();
  // Rendered as inline Markdown (GitHub issue #241).
  await expect(managerThread.getByText('looks good to me')).toBeVisible();
  await expect(managerThread.locator('.comment strong')).toHaveText('good');

  // Manager edits their own comment through the real Edit/Save UI (typing,
  // clicking — not just the pure editComment() unit tests in comments.ts) —
  // still their own session/tab, real WASM crypto re-encrypting the whole
  // commentsBlob on Save.
  await managerThread.getByRole('button', { name: 'Edit' }).click();
  // Edit shows the Markdown source, not the rendered text.
  await expect(
    managerThread.locator('.edit-form input[type=text]'),
  ).toHaveValue('looks **good** to me');
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

  await openCreateFormWith(employee, managerEmail);
  await employee.locator('label.radio', { hasText: 'leads this 1:1' }).click();

  const meetingDate = new Date();
  meetingDate.setDate(meetingDate.getDate() + 3);
  const dd = String(meetingDate.getDate()).padStart(2, '0');
  const mm = String(meetingDate.getMonth() + 1).padStart(2, '0');
  const meetingDateInput = employee.locator('#meeting-date');
  await meetingDateInput.fill(`${dd}.${mm}.${meetingDate.getFullYear()}`);
  await meetingDateInput.blur();
  await employee.getByRole('button', { name: 'Create 1:1' }).click();
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
  // The new text is Markdown (#165): the entry renders it as bold, and the
  // inline edit reopens on the raw source, not the rendered text.
  const postPublishEditedText = `E2E-ENTRY-POST-PUBLISH-EDIT-${Date.now()}`;
  const postPublishEditedSource = `**${postPublishEditedText}**`;
  await answersEditButton(employeeMySide, 'edit').click();
  const outerSaveButton = employeeMySide
    .locator('.answers-edit-actions')
    .getByRole('button', { name: 'Save' });
  await entryRow.getByRole('button', { name: 'Edit' }).click();
  await expect(outerSaveButton).toBeDisabled();
  // An open entry edit counts as unsaved (GitHub issue #166).
  await expect(employeeMySide.locator('.answers-edit-bar')).toContainText(
    'Unsaved changes',
  );
  await entryRow.locator('.entry-edit-input').fill(postPublishEditedSource);
  await entryRow.getByRole('button', { name: 'Save' }).click();
  await expect(entryRow.locator('.entry-text strong')).toHaveText(
    postPublishEditedText,
  );
  await entryRow.getByRole('button', { name: 'Edit' }).click();
  await expect(entryRow.locator('.entry-edit-input')).toHaveValue(
    postPublishEditedSource,
  );
  await entryRow.getByRole('button', { name: 'Cancel' }).click();
  await expect(outerSaveButton).toBeEnabled();
  await outerSaveButton.click();
  await expect(employeeMySide.getByText('Published')).toBeVisible();

  await manager.goto(anketaUrl);
  const managerCounterpartAchievements = manager
    .locator('.side-card')
    .nth(1)
    .locator('.block', { hasText: 'Achievements' });
  await expect(
    managerCounterpartAchievements.locator('.entry-text strong'),
  ).toHaveText(postPublishEditedText);
  await expect(
    managerCounterpartAchievements.locator('.entry-text'),
  ).toHaveText(postPublishEditedText);
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
  await employee.locator('label.radio', { hasText: 'leads this 1:1' }).click();

  const meetingDate = new Date();
  meetingDate.setDate(meetingDate.getDate() + 3);
  const dd = String(meetingDate.getDate()).padStart(2, '0');
  const mm = String(meetingDate.getMonth() + 1).padStart(2, '0');
  const meetingDateInput = employee.locator('#meeting-date');
  await meetingDateInput.fill(`${dd}.${mm}.${meetingDate.getFullYear()}`);
  await meetingDateInput.blur();
  await employee.getByRole('button', { name: 'Create 1:1' }).click();
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
 * GitHub issue #202: the follow-up email for a meeting nobody closed carries
 * two links to its page, told apart by their URL fragment. Each lands on its
 * own part of the page: the archive form's heading, and the "not closed"
 * card's date field.
 */
test("the follow-up email's links land on the archive form and the reschedule field", async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-follow-up');
  const managerEmail = uniqueEmail('manager-follow-up');
  const employee = await activate(browser, createActivationLink(employeeEmail));
  await activate(browser, createActivationLink(managerEmail));

  await employee.goto('/anketas/new');
  await employee
    .getByPlaceholder('Type a name or email to search…')
    .fill(managerEmail);
  await employee.getByRole('button', { name: managerEmail }).click();
  await employee.locator('label.radio', { hasText: 'leads this 1:1' }).click();
  const meetingDate = new Date();
  meetingDate.setDate(meetingDate.getDate() - 2);
  const dd = String(meetingDate.getDate()).padStart(2, '0');
  const mm = String(meetingDate.getMonth() + 1).padStart(2, '0');
  const meetingDateInput = employee.locator('#meeting-date');
  await meetingDateInput.fill(`${dd}.${mm}.${meetingDate.getFullYear()}`);
  await meetingDateInput.blur();
  await employee.getByRole('button', { name: 'Create 1:1' }).click();
  await employee.waitForURL(/\/anketas\/[0-9a-f-]+$/);
  const anketaUrl = employee.url();
  await expect(employee.locator('.overdue-card')).toBeVisible();
  // Without a fragment nothing takes focus.
  await expect(employee.locator('#archive-heading')).not.toBeFocused();

  // A real page load, as from an email client; the fragment alone would only
  // be a same-document navigation.
  await employee.goto('/');
  await employee.goto(`${anketaUrl}#close`);
  await expect(employee.locator('#archive-heading')).toBeFocused();
  await expect(employee.locator('#archive-heading')).toBeInViewport();
  // The fragment is dropped once followed, so a reload doesn't scroll again.
  await expect(employee).toHaveURL(anketaUrl);

  await employee.goto('/');
  await employee.goto(`${anketaUrl}#reschedule`);
  await expect(
    employee.locator('.overdue-card #reschedule-date'),
  ).toBeFocused();
  await expect(employee).toHaveURL(anketaUrl);

  // The link opened in a tab already showing the meeting: only the fragment
  // changes, with no page load.
  await employee.evaluate("window.location.hash = 'close'");
  await expect(employee.locator('#archive-heading')).toBeFocused();
  await expect(employee).toHaveURL(anketaUrl);

  // Moved to a later day since the email: the "not closed" card is gone, and
  // the reschedule link opens the "Change date" row instead.
  const newDate = new Date();
  newDate.setDate(newDate.getDate() + 5);
  const newDd = String(newDate.getDate()).padStart(2, '0');
  const newMm = String(newDate.getMonth() + 1).padStart(2, '0');
  const cardDateField = employee.locator('.overdue-card #reschedule-date');
  await cardDateField.fill(`${newDd}.${newMm}.${newDate.getFullYear()}`);
  await cardDateField.blur();
  await employee
    .locator('.overdue-card')
    .getByRole('button', { name: 'Reschedule' })
    .click();
  await expect(employee.locator('.overdue-card')).toHaveCount(0);
  await expect(employee.locator('.reschedule-row')).toHaveCount(0);

  await employee.evaluate("window.location.hash = 'reschedule'");
  await expect(
    employee.locator('.reschedule-row #reschedule-date'),
  ).toBeFocused();
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
  await employee.locator('label.radio', { hasText: 'leads this 1:1' }).click();

  const meetingDate = new Date();
  meetingDate.setDate(meetingDate.getDate() + 3);
  const dd = String(meetingDate.getDate()).padStart(2, '0');
  const mm = String(meetingDate.getMonth() + 1).padStart(2, '0');
  const meetingDateInput = employee.locator('#meeting-date');
  await meetingDateInput.fill(`${dd}.${mm}.${meetingDate.getFullYear()}`);
  await meetingDateInput.blur();
  await employee.getByRole('button', { name: 'Create 1:1' }).click();
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
  await answersEditButton(employeeMySide, 'edit').click();
  await employeeMySide.locator('textarea').first().fill(markerB);
  await answersEditButton(employeeMySide, 'save').click();
  await expect(employeeMySide.getByText('Published')).toBeVisible();

  // No manager.reload() here — this has to arrive via the live-state poll.
  await expect(
    managerCounterpartSide.locator('.answer-text').first(),
  ).toHaveText(markerB, { timeout: 8000 });

  // Manager comments on that same field from their already-open tab.
  const managerThread = moodNotesThread(managerCounterpartSide);
  await managerThread.getByRole('button', { name: /comment/i }).click();
  await managerThread
    .locator('input[type=text]')
    .fill('nice **progress**, see [doc](https://example.com)');
  await managerThread.getByRole('button', { name: 'Post' }).click();
  await expect(managerThread.getByText('nice progress, see doc')).toBeVisible();

  // Employee's own tab (still open on the same field, myPublished so its own
  // CommentThread instance is rendered) picks up the new comment without a
  // reload, and briefly highlights it (private/live-updates-proposal.md §7).
  const employeeThread = moodNotesThread(employeeMySide);
  const newComment = employeeThread.locator('.comment', {
    hasText: 'nice progress, see doc',
  });
  await expect(newComment).toBeVisible({ timeout: 8000 });
  await expect(newComment).toHaveClass(/recently-arrived/, { timeout: 2000 });
  // A screen reader hears the text as shown, not its Markdown source or the
  // link's URL (GitHub issue #241).
  await expect(employeeThread.locator('.sr-only[aria-live]')).toHaveText(
    `${managerEmail}: nice progress, see doc`,
    { timeout: 2000 },
  );
  // The comment renders as inline Markdown on both sides.
  for (const thread of [managerThread, employeeThread]) {
    await expect(thread.locator('.comment strong')).toHaveText('progress');
    await expect(thread.getByRole('link', { name: 'doc' })).toHaveAttribute(
      'href',
      'https://example.com',
    );
  }

  // A long comment with nowhere to break wraps on a phone instead of
  // widening its thread, and with it the page.
  await manager.setViewportSize({ width: 360, height: 800 });
  const threadWidth = () => managerThread.evaluate((el) => el.clientWidth);
  const widthBefore = await threadWidth();
  const unbroken = `[spec](/${'x'.repeat(90)})`;
  await managerThread.locator('input[type=text]').fill(unbroken);
  await managerThread.getByRole('button', { name: 'Post' }).click();
  await expect(
    managerThread.locator('.comment', { hasText: unbroken }),
  ).toBeVisible();
  expect(await threadWidth()).toBeLessThanOrEqual(widthBefore);
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
  await employee.locator('label.radio', { hasText: 'leads this 1:1' }).click();

  const meetingDate = new Date();
  meetingDate.setDate(meetingDate.getDate() + 3);
  const dd = String(meetingDate.getDate()).padStart(2, '0');
  const mm = String(meetingDate.getMonth() + 1).padStart(2, '0');
  const meetingDateInput = employee.locator('#meeting-date');
  await meetingDateInput.fill(`${dd}.${mm}.${meetingDate.getFullYear()}`);
  await meetingDateInput.blur();
  await employee.getByRole('button', { name: 'Create 1:1' }).click();
  await employee.waitForURL(/\/anketas\/[0-9a-f-]+$/);
  const anketaUrl = employee.url();

  const originalMarker = `E2E-ARCHIVE-ORIGINAL-${Date.now()}`;
  const employeeMySide = employee.locator('.side-card').first();
  await employeeMySide.locator('textarea').first().fill(originalMarker);
  await employeeMySide.getByRole('button', { name: 'Publish' }).click();
  await expect(employeeMySide.getByText('Published')).toBeVisible();

  // Employee opens edit mode and types a change, but never clicks Save —
  // this has to still be sitting open when the counterpart archives below.
  await answersEditButton(employeeMySide, 'edit').click();
  await employeeMySide
    .locator('textarea')
    .first()
    .fill(`${originalMarker}-UNSAVED-EDIT`);
  await expect(answersEditButton(employeeMySide, 'save')).toBeVisible();
  // Archiving from this tab waits for the edit to be saved or cancelled —
  // otherwise the edit would become unsaveable (GitHub issue #130 review).
  await expect(archiveButton(employee)).toBeDisabled();

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
  await archive(manager);
  await expect(archiveButton(manager)).toHaveCount(0);

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

  // The counterpart cancels it as missed (through the API: the "not closed"
  // card's button only appears once the meeting date has passed), with no successor.
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
  await archive(employee);
  expect((await archiveResponse).status()).toBe(409);

  await expect(archiveButton(employee)).toHaveCount(0);
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
  await expect(archiveButton(employee)).toHaveCount(0);
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
  await employee.locator('label.radio', { hasText: 'leads this 1:1' }).click();

  const meetingDate = new Date();
  meetingDate.setDate(meetingDate.getDate() + 3);
  const dd = String(meetingDate.getDate()).padStart(2, '0');
  const mm = String(meetingDate.getMonth() + 1).padStart(2, '0');
  const meetingDateInput = employee.locator('#meeting-date');
  await meetingDateInput.fill(`${dd}.${mm}.${meetingDate.getFullYear()}`);
  await meetingDateInput.blur();
  await employee.getByRole('button', { name: 'Create 1:1' }).click();
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
  const optionalHint = employee.getByText('All fields are optional.', {
    exact: false,
  });
  await expect(optionalHint).toBeVisible();

  // Manager — a separate session — archives the anketa (skipping next-cycle
  // creation, same as the other archive-mid-edit test above).
  await manager.goto(anketaUrl);
  await manager
    .getByRole('checkbox', { name: "Don't create the next meeting" })
    .check({ force: true });
  await archive(manager);
  await expect(archiveButton(manager)).toHaveCount(0);

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
  // Read-only now, so nothing is left to call optional.
  await expect(optionalHint).toHaveCount(0);
});

/**
 * GitHub issue #229: closing a meeting is never one click, and never goes
 * ahead over my own unpublished answers. The incident behind it: an employee
 * with a filled-in draft scrolled to the bottom of the page for Publish and
 * pressed Archive instead, which closed the meeting for both and left the
 * draft unpublishable.
 */
test('closing a meeting asks first, and publishes my unpublished answers before closing', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-confirm-close');
  const managerEmail = uniqueEmail('manager-confirm-close');
  const employee = await activate(browser, createActivationLink(employeeEmail));
  const manager = await activate(browser, createActivationLink(managerEmail));
  const anketaUrl = await createAnketa(employee, managerEmail, 3);
  const employeeMySide = employee.locator('.side-card').first();

  let archiveRequests = 0;
  employee.on('request', (request) => {
    if (request.url().endsWith('/archive')) archiveRequests++;
  });

  // Nothing typed or published by anyone: closing is allowed, and says so.
  await employee
    .getByRole('checkbox', { name: "Don't create the next meeting" })
    .check({ force: true });
  await archiveButton(employee).click();
  const cancel = employee.getByRole('button', { name: 'Cancel' });
  await expect(cancel).toBeFocused();
  await expect(
    employee.getByText('Neither of you has published any answers.'),
  ).toBeVisible();
  await expect(confirmArchiveButton(employee)).toHaveText('Close this 1:1');
  // A cleared next-meeting date stops the close before anything is sent.
  await employee
    .getByRole('checkbox', { name: "Don't create the next meeting" })
    .uncheck({ force: true });
  await employee.locator('#next-meeting-date').fill('');
  await employee.locator('#next-meeting-date').blur();
  await confirmArchiveButton(employee).click();
  await expect(
    employee
      .getByRole('alert')
      .filter({ hasText: 'Enter the next meeting date first.' }),
  ).toBeVisible();
  await employee
    .getByRole('checkbox', { name: "Don't create the next meeting" })
    .check({ force: true });
  await archiveButton(employee).click();
  // Enter on the focused Cancel backs out, with focus on the button again.
  await employee.keyboard.press('Enter');
  await expect(archiveButton(employee)).toBeFocused();
  await expect(confirmArchiveButton(employee)).toHaveCount(0);

  // With a draft, the only way to close is to publish it first.
  const answer = 'an answer nearly lost to the wrong button';
  await employeeMySide.locator('textarea').first().fill(answer);
  // A double click on the button only opens the confirmation: its second
  // click neither confirms nor cancels.
  await archiveButton(employee).dblclick();
  await expect(
    employee.getByText(/Your answers aren't published yet/),
  ).toBeVisible();
  await expect(confirmArchiveButton(employee)).toHaveText('Publish and close');
  expect(archiveRequests).toBe(0);

  const published = employee.waitForResponse((response) =>
    response.url().endsWith('/publish'),
  );
  await confirmArchiveButton(employee).click();
  expect((await published).status()).toBe(200);
  await expectArchived(employee);
  expect(archiveRequests).toBe(1);

  // The manager sees the answer the draft would otherwise have kept.
  await manager.goto(anketaUrl);
  await expect(manager.getByText(answer)).toBeVisible();
});

/**
 * A tab that doesn't know its side is already published (here, a publish
 * whose response was lost; the live-state poll never updates my own side)
 * gets "Publish and close" refused with 409 on every attempt. It must say
 * what to do, keep what was typed and chosen, and work after a reload.
 */
test('closing from a tab that missed its own publish says to reload, and closes after it', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-close-stale');
  const managerEmail = uniqueEmail('manager-close-stale');
  const employee = await activate(browser, createActivationLink(employeeEmail));
  await activate(browser, createActivationLink(managerEmail));
  await createAnketa(employee, managerEmail, 3);
  const employeeMySide = employee.locator('.side-card').first();
  const firstAnswer = employeeMySide.locator('textarea').first();
  await firstAnswer.fill('published unseen');
  const skipNext = employee.getByRole('checkbox', {
    name: "Don't create the next meeting",
  });
  await skipNext.check({ force: true });

  // The server publishes, but this tab never hears back.
  await employee.route(
    '**/publish',
    async (route) => {
      await route.fetch();
      await route.abort();
    },
    { times: 1 },
  );
  await archive(employee);
  await expect(employee.getByRole('alert')).toBeVisible();
  await firstAnswer.fill('typed after the lost publish');

  // The next attempt is refused as already published. The meeting stays
  // open, with the text and the form's choice as they were.
  await archive(employee);
  await expect(
    employee.getByRole('alert').filter({ hasText: /reload the page/ }),
  ).toBeVisible();
  await expect(firstAnswer).toHaveValue('typed after the lost publish');
  await expect(skipNext).toBeChecked();

  // Reloaded, the side is published and closing is a plain confirmation.
  await employee.reload();
  await expect(employeeMySide.getByText('published unseen')).toBeVisible();
  await skipNext.check({ force: true });
  await archiveButton(employee).click();
  await expect(confirmArchiveButton(employee)).toHaveText('Close this 1:1');
  await confirmArchiveButton(employee).click();
  await expectArchived(employee);
});

test('the published side can close a meeting the counterpart never published on, after a warning', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-close-unpublished');
  const managerEmail = uniqueEmail('manager-close-unpublished');
  const employee = await activate(browser, createActivationLink(employeeEmail));
  const manager = await activate(browser, createActivationLink(managerEmail));
  const anketaUrl = await createAnketa(employee, managerEmail, 3);

  await manager.goto(anketaUrl);
  const managerMySide = manager.locator('.side-card').first();
  await managerMySide.locator('textarea').first().fill('the manager side');
  await managerMySide.getByRole('button', { name: 'Publish' }).click();
  await expect(
    managerMySide.getByRole('button', { name: 'Publish' }),
  ).toHaveCount(0);

  await manager
    .getByRole('checkbox', { name: "Don't create the next meeting" })
    .check({ force: true });
  await archiveButton(manager).click();
  await expect(
    manager.getByText(/hasn't published their answers yet/),
  ).toBeVisible();
  await expect(confirmArchiveButton(manager)).toHaveText('Close this 1:1');
  await confirmArchiveButton(manager).click();
  await expectArchived(manager);
});

/**
 * GitHub issue #251: the create form never carries a role over from another
 * meeting. The pair here already has a 1:1, created in the employee's
 * browser: before #251 that browser's last role and the pair's history each
 * preselected a role on the next form.
 */
test('the create form never preselects a role, and changing the colleague clears a clicked one', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-role');
  const managerEmail = uniqueEmail('manager-role');
  const thirdEmail = uniqueEmail('third-role');
  const employee = await activate(browser, createActivationLink(employeeEmail));
  const manager = await activate(browser, createActivationLink(managerEmail));
  await (
    await activate(browser, createActivationLink(thirdEmail))
  )
    .context()
    .close();
  await createAnketa(employee, managerEmail, 3);

  const roles = (page: Page) => ({
    manager: page.getByRole('radio', { name: 'I lead this 1:1' }),
    employee: page.getByRole('radio', { name: 'leads this 1:1' }),
  });
  const expectNoRole = async (page: Page) => {
    await expect(roles(page).manager).not.toBeChecked();
    await expect(roles(page).employee).not.toBeChecked();
    await expect(
      page.getByRole('button', { name: 'Create 1:1' }),
    ).toBeDisabled();
  };

  for (const [page, colleagueEmail, lastRole] of [
    [employee, managerEmail, 'employee'],
    [manager, employeeEmail, 'manager'],
  ] as const) {
    // No colleague yet: a role can't be chosen for nobody.
    await page.goto('/anketas/new');
    await expect(roles(page).manager).toBeDisabled();
    await expect(roles(page).employee).toBeDisabled();
    await expect(page.getByText('Choose the counterpart first.')).toBeVisible();
    await expect(
      page.getByRole('radio', { name: 'The counterpart leads this 1:1' }),
    ).toBeDisabled();
    await expect(page.locator('form [role="status"]')).toBeEmpty();
    await expectNoRole(page);
    // A colleague this user has met before: still nothing selected.
    await pickColleague(page, colleagueEmail);
    await expect(roles(page).manager).toBeEnabled();
    await expect(page.getByText('Choose the counterpart first.')).toHaveCount(
      0,
    );
    // The options name the colleague (GitHub issue #252); an account with no
    // display name shows as its email.
    await expect(
      page.getByRole('radio', { name: `${colleagueEmail} leads this 1:1` }),
    ).toBeEnabled();
    await expect(
      page.getByText(
        `I answer as the manager, ${colleagueEmail} answers as the employee.`,
      ),
    ).toBeVisible();
    await expect(
      page.getByText(
        `${colleagueEmail} answers as the manager, I answer as the employee.`,
      ),
    ).toBeVisible();
    // The pair has a 1:1: my role in it is stated under that role's
    // option, and selects nothing.
    await expect(
      page
        .locator(`#role-${lastRole}-about`)
        .getByText(`Your role in your most recent 1:1 together: ${lastRole}.`),
    ).toBeVisible();
    await expect(
      page.getByText(/^Your role in your most recent 1:1/),
    ).toHaveCount(1);
    await expect(page.locator('form [role="status"]')).toHaveText(
      'Choose who leads this 1:1.',
    );
    await expectNoRole(page);
  }

  // A clicked role is for the colleague chosen at that moment: any change
  // of the colleague field clears it.
  await manager.locator('label.radio', { hasText: 'I lead this 1:1' }).click();
  await expect(roles(manager).manager).toBeChecked();
  await expect(manager.locator('form [role="status"]')).toHaveText(
    'Choose the meeting date.',
  );
  // Typing in the field: nobody is chosen, so no role can be.
  await manager.getByPlaceholder('Type a name or email to search…').fill('x');
  await expect(roles(manager).manager).toBeDisabled();
  await expectNoRole(manager);
  // Another colleague: the role wasn't chosen for them.
  await pickColleague(manager, thirdEmail);
  await expect(roles(manager).manager).toBeEnabled();
  // A new pair has no 1:1 to mention.
  await expect(
    manager.getByText(/^Your role in your most recent 1:1/),
  ).toHaveCount(0);
  await expectNoRole(manager);
  // The first colleague again: it isn't brought back unasked either.
  await pickColleague(manager, employeeEmail);
  await expectNoRole(manager);
});

/**
 * Creates an anketa from `creator`'s side (always as the employee; the create
 * form has no default role) against `counterpartEmail`, `daysAhead` days out, via
 * the real /anketas/new form, and returns its URL. `templateLabel` is the
 * picker's visible label (createAnketa.template* in en.json); omitted, the
 * form's own default ('regular') is left selected. Only the meeting-templates
 * tests below use this — the older tests above predate it and still inline
 * the same steps.
 */
/** Opens the create form and picks `counterpartEmail` in its typeahead. */
async function openCreateFormWith(
  page: Page,
  counterpartEmail: string,
): Promise<void> {
  await page.goto('/anketas/new');
  await pickColleague(page, counterpartEmail);
}

/** Types `email` into the create form's colleague field and picks them. */
async function pickColleague(page: Page, email: string): Promise<void> {
  await page.getByPlaceholder('Type a name or email to search…').fill(email);
  await page.getByRole('button', { name: email }).click();
}

async function createAnketa(
  creator: Page,
  counterpartEmail: string,
  daysAhead: number,
  templateLabel?: string,
): Promise<string> {
  await openCreateFormWith(creator, counterpartEmail);
  await creator.locator('label.radio', { hasText: 'leads this 1:1' }).click();

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
  // Nothing is missing any more, and the line under the button says so.
  await expect(creator.locator('form [role="status"]')).toBeEmpty();
  await creator.getByRole('button', { name: 'Create 1:1' }).click();
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
 * browser-generated key actually unseals. This template's successor falls
 * back to 'regular' (Anketa::NEXT_CYCLE_TEMPLATE_KEY); 'lightweight' is the
 * one that doesn't, see the Quick check-in test below.
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
  // The "Next meeting type" default is the same rule (GitHub issue #140), and
  // an untouched picker sends nothing, leaving the choice to the server.
  await expect(manager.getByLabel('Next meeting type')).toHaveValue('regular');
  const archiveRequest = manager.waitForRequest((request) =>
    request.url().endsWith('/archive'),
  );
  await archive(manager);
  expect((await archiveRequest).postDataJSON()).not.toHaveProperty(
    'nextTemplateKey',
  );
  await expect(archiveButton(manager)).toHaveCount(0);

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
  await archive(employee);
  await expect(archiveButton(employee)).toHaveCount(0);

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
  await employee.locator('label.radio', { hasText: 'leads this 1:1' }).click();
  await expect(
    employee.getByText('This pair already has an open 1:1'),
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
  await expect(employee.getByText('This is a one-off 1:1')).toBeVisible();
  await expect(
    employee.getByRole('checkbox', { name: "Don't create the next meeting" }),
  ).toHaveCount(0);
  await expect(employee.locator('#next-meeting-date')).toHaveCount(0);
  await expect(employee.locator('#next-meeting-type')).toHaveCount(0);

  await archive(employee);
  await expect(archiveButton(employee)).toHaveCount(0);

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
 * Opens the pair's one still-open anketa from `page`'s list — the successor
 * an archive with a next meeting just created — and returns its URL.
 */
async function openSuccessor(page: Page, archivedUrl: string): Promise<string> {
  await page.goto('/');
  const rows = page.locator('.anketa-row');
  await expect(rows).toHaveCount(2);
  await rows
    .filter({ hasNot: page.locator('.tag', { hasText: 'archived' }) })
    .click();
  await page.waitForURL(/\/anketas\/[0-9a-f-]+$/);
  expect(page.url()).not.toBe(archivedUrl);
  return page.url();
}

/** `page`'s own side renders the Career growth question set. */
async function expectCareerGrowthOnMySide(
  page: Page,
  role: 'employee' | 'manager',
): Promise<void> {
  const mySide = page.locator('.side-card').first();
  if (role === 'manager') {
    await expect(
      mySide.getByRole('heading', {
        name: 'Stretch opportunity and sponsorship',
      }),
    ).toBeVisible();
  } else {
    await expect(
      mySide.getByRole('heading', { name: 'Mood', exact: true }),
    ).toBeVisible();
    // Regular-only, absent from Career growth.
    await expect(
      mySide.getByRole('heading', { name: 'Feelings', exact: true }),
    ).toHaveCount(0);
  }
}

/**
 * GitHub issue #140: the archive form's "Next meeting type" picker. Its
 * default is the server's per-template recurrence rule; picking another type
 * creates the successor with that type, on both participants' pages. Hidden
 * with "Don't create the next meeting" (and for a one-off, covered above).
 */
test('archiving with a chosen next meeting type creates the successor with that type', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-next-type');
  const managerEmail = uniqueEmail('manager-next-type');
  const employeeToken = createActivationLink(employeeEmail);
  const managerToken = createActivationLink(managerEmail);

  const employee = await activate(browser, employeeToken);
  const manager = await activate(browser, managerToken);

  const anketaUrl = await createAnketa(employee, managerEmail, 3);

  await manager.goto(anketaUrl);
  const picker = manager.getByLabel('Next meeting type');
  await expect(picker).toHaveValue('regular');
  await manager
    .getByRole('checkbox', { name: "Don't create the next meeting" })
    .check({ force: true });
  await expect(picker).toHaveCount(0);
  await manager
    .getByRole('checkbox', { name: "Don't create the next meeting" })
    .uncheck({ force: true });
  await picker.selectOption({ label: 'Career growth' });

  const archiveRequest = manager.waitForRequest((request) =>
    request.url().endsWith('/archive'),
  );
  await archive(manager);
  expect((await archiveRequest).postDataJSON()).toMatchObject({
    nextTemplateKey: 'career_growth',
  });
  await expect(archiveButton(manager)).toHaveCount(0);

  const successorUrl = await openSuccessor(manager, anketaUrl);
  await expectCareerGrowthOnMySide(manager, 'manager');
  // The successor's own default goes back to the per-template rule.
  await expect(manager.getByLabel('Next meeting type')).toHaveValue('regular');

  await employee.goto(successorUrl);
  await expectCareerGrowthOnMySide(employee, 'employee');
});

/** `page`'s own side renders the Quick check-in question set. */
async function expectQuickCheckInOnMySide(
  page: Page,
  role: 'employee' | 'manager',
): Promise<void> {
  const mySide = page.locator('.side-card').first();
  await expect(
    mySide.getByRole('heading', {
      name:
        role === 'employee'
          ? 'Highlights and where I need help'
          : 'How can I help / what gets in the way',
    }),
  ).toBeVisible();
  // Regular-only on either side, absent from the Quick check-in.
  await expect(
    mySide.getByRole('heading', {
      name: role === 'employee' ? 'Feelings' : 'Achievements worth recognizing',
      exact: true,
    }),
  ).toHaveCount(0);
}

/**
 * GitHub issue #208: the Quick check-in ('lightweight') template reaches both
 * sides, and unlike every other non-default built-in template its successor
 * stays on it (Anketa::NEXT_CYCLE_TEMPLATE_KEY), with nothing chosen on the
 * archive form.
 */
test('a Quick check-in reaches both sides, and its successor stays a Quick check-in', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-quick');
  const managerEmail = uniqueEmail('manager-quick');
  const employeeToken = createActivationLink(employeeEmail);
  const managerToken = createActivationLink(managerEmail);

  const employee = await activate(browser, employeeToken);
  const manager = await activate(browser, managerToken);

  const anketaUrl = await createAnketa(
    employee,
    managerEmail,
    3,
    'Quick check-in',
  );
  await expectQuickCheckInOnMySide(employee, 'employee');

  // The employee answers the template's own question, and the manager's
  // browser decrypts it against the same question set.
  const marker = `E2E-QUICK-MARKER-${Date.now()}`;
  const employeeMySide = employee.locator('.side-card').first();
  await questionBlock(employeeMySide, 'Highlights and where I need help')
    .locator('textarea')
    .fill(marker);
  await employeeMySide.getByRole('button', { name: 'Publish' }).click();
  await expect(employeeMySide.getByText('Published')).toBeVisible();

  await manager.goto(anketaUrl);
  await expectQuickCheckInOnMySide(manager, 'manager');
  await expect(manager.locator('.side-card').nth(1)).toContainText(marker);

  await expect(manager.getByLabel('Next meeting type')).toHaveValue(
    'lightweight',
  );
  const archiveRequest = manager.waitForRequest((request) =>
    request.url().endsWith('/archive'),
  );
  await archive(manager);
  expect((await archiveRequest).postDataJSON()).not.toHaveProperty(
    'nextTemplateKey',
  );
  await expect(archiveButton(manager)).toHaveCount(0);

  const successorUrl = await openSuccessor(manager, anketaUrl);
  await expectQuickCheckInOnMySide(manager, 'manager');
  await expect(manager.getByLabel('Next meeting type')).toHaveValue(
    'lightweight',
  );

  await employee.goto(successorUrl);
  await expectQuickCheckInOnMySide(employee, 'employee');
  // The list labels the open successor with its meeting type.
  await employee.goto('/');
  await expect(
    employee
      .locator('.anketa-row')
      .filter({ hasNot: employee.locator('.tag', { hasText: 'archived' }) }),
  ).toContainText('Quick check-in');

  // A 1:1 created next to the open Quick check-in is a one-off. The form
  // says how to change the pair's regular meetings also with Regular
  // check-in chosen: this pair's next regular meeting isn't one.
  await employee.goto('/anketas/new');
  await employee
    .getByPlaceholder('Type a name or email to search…')
    .fill(managerEmail);
  await employee.getByRole('button', { name: managerEmail }).click();
  const howToSwitch = employee.getByText(
    'choose it as the next meeting type when archiving the current one',
  );
  await expect(
    employee.getByRole('radio', { name: 'Regular check-in' }),
  ).toBeChecked();
  await expect(howToSwitch).toBeVisible();
});

/**
 * GitHub issue #140: "Didn't happen" (cancel as missed) on the "not closed"
 * card archives with whatever the archive form currently shows, next meeting
 * type included.
 *
 * Also GitHub issue #201: a meeting past its date reads as a neutral "not
 * closed" in the list and on its page, and the card's first action leads to
 * the archive form.
 */
test('cancel as missed creates the successor with the chosen next meeting type', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-next-type-missed');
  const managerEmail = uniqueEmail('manager-next-type-missed');
  const employeeToken = createActivationLink(employeeEmail);
  const managerToken = createActivationLink(managerEmail);

  const employee = await activate(browser, employeeToken);
  await activate(browser, managerToken);

  const anketaUrl = await createAnketa(employee, managerEmail, -3);
  const card = employee.locator('.overdue-card');
  await expect(card).toContainText(/^The 1:1 on \S+ isn't closed yet/);
  await expect(employee.locator('p.meta .tag-neutral')).toHaveText(
    'not closed',
  );
  await card
    .getByRole('button', { name: 'Close and schedule the next one' })
    .click();
  const archiveHeading = employee.getByRole('heading', { name: 'Archive' });
  await expect(archiveHeading).toBeFocused();
  await expect(archiveHeading).toBeInViewport();

  await employee.goto('/');
  await expect(employee.locator('.tag-neutral')).toHaveText('not closed');
  await employee.goto(anketaUrl);
  await expect(card).toBeVisible();

  await employee
    .getByLabel('Next meeting type')
    .selectOption({ label: 'Career growth' });
  const archiveRequest = employee.waitForRequest((request) =>
    request.url().endsWith('/archive'),
  );
  // Asks for confirmation first, like the archive form's button (#229).
  await employee.getByRole('button', { name: "Didn't happen" }).click();
  await employee
    .locator('.overdue-card')
    .getByRole('button', { name: 'Close as missed' })
    .click();
  expect((await archiveRequest).postDataJSON()).toMatchObject({
    missed: true,
    nextTemplateKey: 'career_growth',
  });
  await expect(employee.locator('.overdue-card')).toHaveCount(0);
  await expect(employee.getByText('missed', { exact: true })).toBeVisible();

  await openSuccessor(employee, anketaUrl);
  await expectCareerGrowthOnMySide(employee, 'employee');
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
  await expect(
    mySide.locator('.answers-edit-actions').getByRole('button', {
      name: 'Saving…',
    }),
  ).toBeDisabled();
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
  await answersEditButton(employeeMySide, 'edit').click();
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
  await answersEditButton(employeeMySide, 'edit').click();
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
  await archive(manager);
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
  await archive(manager);
  await expectArchived(manager);
  await employee.reload();
  await expectArchived(employee);
  const employeeMySide = employee.locator('.side-card').first();
  await expect(employeeMySide.getByText('archived')).toBeVisible();
  await expect(employeeMySide.locator('textarea')).toHaveCount(0);
  await expect(employeeMySide.locator('.field')).toHaveCount(0);
  // The support template's employee side has five question blocks.
  await expect(employeeMySide.locator('.block')).toHaveCount(5);
  await expect(employeeMySide.locator('.block-empty')).toHaveCount(5);
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
  await answersEditButton(employeeMySide, 'edit').click();
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
  await archive(manager);
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
  await answersEditButton(employeeMySide, 'edit').click();
  await employeeMySide.locator('textarea').first().fill('');
  await answersEditButton(employeeMySide, 'save').click();
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

/**
 * Company templates in anketas (GitHub issue #144, #133 §10 e2e 2–4 and 6).
 * The manager is the company admin and writes the template through the admin
 * API, the way the editor saves it (the editor has its own spec). The e2e
 * company is shared by every test, so each template gets a unique name.
 */
const SPRINT_QUESTION = 'How was the sprint?';
const SPRINT_NOTES = 'Notes for the sprint';

function sprintDefinition(questionTitle: string): unknown {
  return {
    schemaVersion: 1,
    employee: [
      { kind: 'builtin', questionId: 'mood' },
      {
        kind: 'custom',
        id: 'c_e2eradio01',
        title: questionTitle,
        field: {
          id: 'c_e2eradio02',
          type: 'radio',
          options: [
            { value: 'o_great001', label: 'Great' },
            { value: 'o_rough001', label: 'Rough' },
          ],
        },
      },
    ],
    manager: [
      {
        kind: 'custom',
        id: 'c_e2enotes01',
        title: SPRINT_NOTES,
        field: { id: 'c_e2enotes02', type: 'text' },
      },
    ],
  };
}

/** A request from `page`'s session, with its CSRF token, as the app sends it. */
async function sessionRequest(
  page: Page,
  method: 'POST' | 'PUT',
  path: string,
  data: unknown,
): Promise<{ id?: string }> {
  const { token } = (await (
    await page.request.get('/api/csrf-token')
  ).json()) as {
    token: string;
  };
  const response = await page.request.fetch(path, {
    method,
    headers: { 'X-CSRF-Token': token },
    data,
  });
  expect(response.ok(), await response.text()).toBe(true);
  return (await response.json()) as { id?: string };
}

/** Creates a company template as `admin` and returns its id. */
async function createCompanyTemplate(
  admin: Page,
  name: string,
): Promise<string> {
  const { id } = await sessionRequest(admin, 'POST', '/api/admin/templates', {
    name,
    definition: sprintDefinition(SPRINT_QUESTION),
  });
  expect(id).toBeDefined();
  return id as string;
}

/** A pair of a new employee and a new admin who is the manager. */
async function employeeAndAdmin(
  browser: Browser,
  label: string,
): Promise<{ employee: Page; admin: Page; adminEmail: string }> {
  const employeeEmail = uniqueEmail(`employee-${label}`);
  const adminEmail = uniqueEmail(`admin-${label}`);
  const employeeToken = createActivationLink(employeeEmail);
  const adminToken = createActivationLink(adminEmail, true);
  const employee = await activate(browser, employeeToken);
  const admin = await activate(browser, adminToken);
  return { employee, admin, adminEmail };
}

/** Archives from `page`'s archive form with `templateName` picked as the next type. */
async function archiveChoosing(
  page: Page,
  templateName: string,
): Promise<void> {
  await page
    .getByLabel('Next meeting type')
    .selectOption({ label: templateName });
  const archiveRequest = page.waitForRequest((request) =>
    request.url().endsWith('/archive'),
  );
  await archive(page);
  expect((await archiveRequest).postDataJSON()).toMatchObject({
    nextTemplateKey: 'custom',
  });
  await expectArchived(page);
}

test('a pair moves onto a company template at archive, and both sides use it', async ({
  browser,
}) => {
  const { employee, admin, adminEmail } = await employeeAndAdmin(
    browser,
    'custom-move',
  );
  const templateName = `Sprint check ${Date.now()}`;
  await createCompanyTemplate(admin, templateName);

  const regularUrl = await createAnketa(employee, adminEmail, 3);
  await admin.goto(regularUrl);
  await archiveChoosing(admin, templateName);
  const successorUrl = await openSuccessor(admin, regularUrl);

  // The admin, as the manager, gets the template's manager side.
  const adminMySide = admin.locator('.side-card').first();
  await expect(
    adminMySide.getByRole('heading', { name: SPRINT_NOTES }),
  ).toBeVisible();
  await expect(admin.locator('.meta')).toContainText(templateName);
  // A company template recurs by default.
  await expect(admin.getByLabel('Next meeting type')).toHaveValue(/^custom:/);

  // The employee gets the built-in Mood and the custom radio question, and
  // answers the custom one.
  await employee.goto(successorUrl);
  const employeeMySide = employee.locator('.side-card').first();
  await expect(
    employeeMySide.getByRole('heading', { name: 'Mood', exact: true }),
  ).toBeVisible();
  const sprintBlock = questionBlock(employeeMySide, SPRINT_QUESTION);
  await sprintBlock.locator('label.radio', { hasText: 'Rough' }).click();
  await employeeMySide.getByRole('button', { name: 'Publish' }).click();
  await expect(employeeMySide.getByText('Published')).toBeVisible();

  // The manager sees the custom answer, and only the chosen option.
  await admin.reload();
  const counterpartSprint = questionBlock(
    admin.locator('.side-card').nth(1),
    SPRINT_QUESTION,
  );
  await expect(counterpartSprint.locator('.answer-choice')).toHaveText('Rough');
  await expect(counterpartSprint).not.toContainText('Great');

  // Both lists label the open anketa with the template's name.
  for (const page of [employee, admin]) {
    await page.goto('/');
    await expect(
      page.locator('.anketa-row', {
        hasNot: page.locator('.tag', { hasText: 'archived' }),
      }),
    ).toContainText(templateName);
  }
});

test('editing a company template leaves an open anketa as it is, and its successor gets the edit', async ({
  browser,
}) => {
  const { employee, admin, adminEmail } = await employeeAndAdmin(
    browser,
    'custom-edit',
  );
  const templateName = `Sprint edit ${Date.now()}`;
  const templateId = await createCompanyTemplate(admin, templateName);

  // Created straight from the create page's company templates.
  const anketaUrl = await createAnketa(employee, adminEmail, 3, templateName);
  const employeeMySide = employee.locator('.side-card').first();
  await expect(
    employeeMySide.getByRole('heading', { name: SPRINT_QUESTION }),
  ).toBeVisible();

  const editedQuestion = 'How did the sprint really go?';
  await sessionRequest(admin, 'PUT', `/api/admin/templates/${templateId}`, {
    name: templateName,
    description: '',
    definition: sprintDefinition(editedQuestion),
    expectedVersion: 1,
  });

  // The open anketa keeps the version it was created on.
  await employee.reload();
  await expect(
    employeeMySide.getByRole('heading', { name: SPRINT_QUESTION }),
  ).toBeVisible();
  await expect(
    employeeMySide.getByRole('heading', { name: editedQuestion }),
  ).toHaveCount(0);

  // Archived with the untouched default, the successor is on the new version.
  await archive(employee);
  await expectArchived(employee);
  await openSuccessor(employee, anketaUrl);
  await expect(
    employee.locator('.side-card').first().getByRole('heading', {
      name: editedQuestion,
    }),
  ).toBeVisible();
});

test('once a company template is archived, its anketa defaults back to Regular', async ({
  browser,
}) => {
  const { employee, admin, adminEmail } = await employeeAndAdmin(
    browser,
    'custom-retired',
  );
  const templateName = `Sprint retired ${Date.now()}`;
  const templateId = await createCompanyTemplate(admin, templateName);
  const anketaUrl = await createAnketa(employee, adminEmail, 3, templateName);

  await sessionRequest(
    admin,
    'PUT',
    `/api/admin/templates/${templateId}/archived`,
    {
      archived: true,
    },
  );

  await employee.reload();
  // The anketa still renders its own version.
  await expect(
    employee.locator('.side-card').first().getByRole('heading', {
      name: SPRINT_QUESTION,
    }),
  ).toBeVisible();
  await expect(employee.getByLabel('Next meeting type')).toHaveValue('regular');
  await expect(
    employee.getByText('Your admin retired this template'),
  ).toBeVisible();

  await archive(employee);
  await expectArchived(employee);
  await openSuccessor(employee, anketaUrl);
  await expect(
    employee
      .locator('.side-card')
      .first()
      .getByRole('heading', { name: 'Feelings', exact: true }),
  ).toBeVisible();
});

test('a chosen template archived while the archive form is open: the error, a reset to Regular, then the archive', async ({
  browser,
}) => {
  const { employee, admin, adminEmail } = await employeeAndAdmin(
    browser,
    'custom-race',
  );
  const templateName = `Sprint race ${Date.now()}`;
  const templateId = await createCompanyTemplate(admin, templateName);
  const anketaUrl = await createAnketa(employee, adminEmail, 3);

  await employee
    .getByLabel('Next meeting type')
    .selectOption({ label: templateName });
  const nextDate = employee.locator('#next-meeting-date');
  const chosen = new Date();
  chosen.setDate(chosen.getDate() + 20);
  const chosenText = `${String(chosen.getDate()).padStart(2, '0')}.${String(chosen.getMonth() + 1).padStart(2, '0')}.${chosen.getFullYear()}`;
  await nextDate.fill(chosenText);
  await nextDate.blur();

  await sessionRequest(
    admin,
    'PUT',
    `/api/admin/templates/${templateId}/archived`,
    {
      archived: true,
    },
  );

  await archive(employee);
  await expect(
    employee.getByText('This template is no longer available.'),
  ).toBeVisible();
  // Still open, back on the default, with the chosen date kept.
  await expect(archiveButton(employee)).toBeEnabled();
  await expect(employee.getByLabel('Next meeting type')).toHaveValue('regular');
  await expect(nextDate).toHaveValue(chosenText);

  const archiveRequest = employee.waitForRequest((request) =>
    request.url().endsWith('/archive'),
  );
  await archive(employee);
  expect((await archiveRequest).postDataJSON()).not.toHaveProperty(
    'nextTemplateKey',
  );
  await expectArchived(employee);
  await openSuccessor(employee, anketaUrl);
  await expect(
    employee
      .locator('.side-card')
      .first()
      .getByRole('heading', { name: 'Feelings', exact: true }),
  ).toBeVisible();
});

test("an anketa whose questions can't be loaded still archives", async ({
  browser,
}) => {
  const { employee, admin, adminEmail } = await employeeAndAdmin(
    browser,
    'custom-unloadable',
  );
  const templateName = `Sprint unloadable ${Date.now()}`;
  await createCompanyTemplate(admin, templateName);

  let failVersions = true;
  await employee.route('**/api/template-versions/*', (route: Route) =>
    failVersions
      ? route.fulfill({ status: 500, body: '{}' })
      : route.continue(),
  );
  const anketaUrl = await createAnketa(employee, adminEmail, 3, templateName);

  await expect(
    employee.getByText("This 1:1's questions couldn't be loaded."),
  ).toBeVisible();
  await expect(employee.locator('.side-card')).toHaveCount(0);

  // Try again keeps keyboard focus: back on the button if it fails again,
  // on my side's heading once the questions are there.
  const retry = employee.getByRole('button', { name: 'Try again' });
  await retry.focus();
  await employee.keyboard.press('Enter');
  await expect(retry).toBeFocused();
  failVersions = false;
  await employee.keyboard.press('Enter');
  await expect(
    employee.locator('.side-card').first().getByRole('heading', {
      name: SPRINT_QUESTION,
    }),
  ).toBeVisible();
  await expect(employee.locator('[data-my-side-heading]')).toBeFocused();

  // And with it failing, the archive card still works.
  failVersions = true;
  await employee.reload();
  await expect(
    employee.getByText("This 1:1's questions couldn't be loaded."),
  ).toBeVisible();
  await archive(employee);
  await expectArchived(employee);
  failVersions = false;
  await openSuccessor(employee, anketaUrl);
});

test('discussed question checkboxes live-sync across sessions and freeze on archive', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-discussed');
  const managerEmail = uniqueEmail('manager-discussed');
  const employeeToken = createActivationLink(employeeEmail);
  const managerToken = createActivationLink(managerEmail);

  const employee = await activate(browser, employeeToken);
  const manager = await activate(browser, managerToken);

  const anketaUrl = await createAnketa(employee, managerEmail, 3);

  // Employee publishes their answers so manager can see questions on counterpart side
  const employeeMySide = employee.locator('.side-card').first();
  await employeeMySide.locator('textarea').first().fill('Initial mood update');
  await employeeMySide.getByRole('button', { name: 'Publish' }).click();
  await expect(employeeMySide.getByText('Published')).toBeVisible();

  // Manager opens the anketa once and keeps the tab open
  await manager.goto(anketaUrl);

  const employeeMoodBlock = questionBlock(employeeMySide, 'Mood');
  const employeeMoodToggle = employeeMoodBlock.getByRole('checkbox', {
    name: /discussed/i,
  });

  const managerCounterpartSide = manager.locator('.side-card').nth(1);
  const managerMoodBlock = questionBlock(managerCounterpartSide, 'Mood');
  const managerMoodToggle = managerMoodBlock.getByRole('checkbox', {
    name: /discussed/i,
  });

  await expect(employeeMoodToggle).not.toBeChecked();
  await expect(managerMoodToggle).not.toBeChecked();

  // Employee marks Mood as discussed
  await employeeMoodToggle.click();
  await expect(employeeMoodToggle).toBeChecked();
  await expect(employeeMoodBlock).toHaveClass(/discussed/);

  // Manager's tab receives the update via live-state poll without reloading
  await expect(managerMoodToggle).toBeChecked({ timeout: 8000 });
  await expect(managerMoodBlock).toHaveClass(/discussed/);

  // Manager unchecks Mood from their own tab
  await managerMoodToggle.click();
  await expect(managerMoodToggle).not.toBeChecked();
  await expect(managerMoodBlock).not.toHaveClass(/discussed/);

  // Employee's tab receives the uncheck via poll
  await expect(employeeMoodToggle).not.toBeChecked({ timeout: 8000 });
  await expect(employeeMoodBlock).not.toHaveClass(/discussed/);

  // Two clicks in quick succession: the second is queued behind the first
  // save, not dropped, and both reach the counterpart.
  const employeeWorkloadToggle = questionBlock(
    employeeMySide,
    'Workload',
  ).getByRole('checkbox', { name: /discussed/i });
  const managerWorkloadToggle = questionBlock(
    managerCounterpartSide,
    'Workload',
  ).getByRole('checkbox', { name: /discussed/i });
  await employeeMoodToggle.click();
  await employeeWorkloadToggle.click();
  await expect(employeeMoodToggle).toBeChecked();
  await expect(employeeWorkloadToggle).toBeChecked();
  await expect(managerMoodToggle).toBeChecked({ timeout: 8000 });
  await expect(managerWorkloadToggle).toBeChecked({ timeout: 8000 });

  // Uncompleted items do not block archiving; archiving succeeds
  await archive(employee);
  await expectArchived(employee);

  // Archived state renders checkboxes as disabled (read-only) while preserving state
  const employeeArchivedMood = questionBlock(
    employee.locator('.side-card').first(),
    'Mood',
  );
  const employeeArchivedToggle = employeeArchivedMood.getByRole('checkbox', {
    name: /discussed/i,
  });
  await expect(employeeArchivedToggle).toBeDisabled();
  await expect(employeeArchivedToggle).toBeChecked();
  await expect(employeeArchivedMood).toHaveClass(/discussed/);

  // An unticked block shows no checkbox once archived, on either tab.
  const growthTitle = 'Growth. What did you learn, discover, take away?';
  await expect(
    questionBlock(employee.locator('.side-card').first(), growthTitle),
  ).toBeVisible();
  await expect(
    questionBlock(manager.locator('.side-card').nth(1), growthTitle),
  ).toBeVisible();
  await expect(
    questionBlock(
      employee.locator('.side-card').first(),
      growthTitle,
    ).getByRole('checkbox', { name: /discussed/i }),
  ).toHaveCount(0);
  await expect(
    questionBlock(manager.locator('.side-card').nth(1), growthTitle).getByRole(
      'checkbox',
      { name: /discussed/i },
    ),
  ).toHaveCount(0, { timeout: 8000 });
});

/** The shared "Topics to discuss" card (GitHub issue #206), above both sides. */
function topicsCard(page: Page): Locator {
  return page.locator('section.card', {
    has: page.getByRole('heading', { name: 'Topics to discuss' }),
  });
}

/** The topic row showing exactly `text`. */
function topicRow(page: Page, text: string): Locator {
  return topicsCard(page).locator('.topic', {
    has: page.getByText(text, { exact: true }),
  });
}

async function addTopic(page: Page, text: string): Promise<void> {
  await topicsCard(page).getByPlaceholder('Add a topic…').fill(text);
  await topicsCard(page).getByRole('button', { name: 'Add' }).click();
  await expect(topicRow(page, text)).toBeVisible();
}

/**
 * GitHub issue #206: the shared topics list. Unlike answers, a topic is
 * visible to the other side at once, with neither side published; either
 * side ticks it off; and only the topics not ticked off move to the pair's
 * next meeting, re-encrypted under that meeting's key.
 */
test('topics to discuss are shared before publishing, live-sync, and carry forward when not discussed', async ({
  browser,
}) => {
  // About a dozen waits on the 4s live-update poll: well over half the
  // default 60s on a fast machine.
  test.setTimeout(150_000);
  const employeeEmail = uniqueEmail('employee-topics');
  const managerEmail = uniqueEmail('manager-topics');
  const employee = await activate(browser, createActivationLink(employeeEmail));
  const manager = await activate(browser, createActivationLink(managerEmail));

  const anketaUrl = await createAnketa(employee, managerEmail, 3);
  // The manager keeps this tab open throughout: everything below reaches it
  // by the live-update poll alone.
  await manager.goto(anketaUrl);
  await expect(topicsCard(manager).getByText('No topics yet.')).toBeVisible();
  // The card comes before both sides' answers.
  await expect(manager.locator('.anketa-main > *').first()).toContainText(
    'Topics to discuss',
  );

  const budget = `E2E-TOPIC-BUDGET-${Date.now()}`;
  const rotation = `E2E-TOPIC-ROTATION-${Date.now()}`;
  const hiring = `E2E-TOPIC-HIRING-${Date.now()}`;

  // Nobody has published anything, and the manager sees the topic anyway.
  await addTopic(employee, budget);
  await expect(topicRow(manager, budget)).toBeVisible({ timeout: 8000 });
  await expect(manager.getByText('Not published yet.')).toBeVisible();

  // And the other way round. The employee's own topic is still there.
  await addTopic(manager, rotation);
  await addTopic(manager, hiring);
  await expect(topicRow(employee, rotation)).toBeVisible({ timeout: 8000 });
  await expect(topicRow(employee, hiring)).toBeVisible();
  await expect(topicsCard(employee).locator('.topic')).toHaveCount(3);

  // Only the author edits or deletes a topic.
  await expect(
    topicRow(manager, budget).getByRole('button', { name: 'Edit' }),
  ).toHaveCount(0);
  await expect(
    topicRow(manager, budget).getByRole('button', { name: 'Delete' }),
  ).toHaveCount(0);
  await expect(
    topicRow(employee, budget).getByRole('button', { name: 'Edit' }),
  ).toBeVisible();

  // The author edits theirs in place; the counterpart gets the new text.
  const budgetEdited = `${budget}-EDITED`;
  await topicRow(employee, budget)
    .getByRole('button', { name: 'Edit' })
    .click();
  const editInput = topicsCard(employee).locator('.topic-edit-input');
  await expect(editInput).toBeFocused();
  await editInput.fill(budgetEdited);
  await editInput.press('Enter');
  await expect(topicRow(employee, budgetEdited)).toBeVisible();
  await expect(topicRow(manager, budgetEdited)).toBeVisible({ timeout: 8000 });
  await expect(topicRow(manager, budget)).toHaveCount(0);

  // Either side ticks any topic off, their own or not.
  await topicRow(manager, budgetEdited).getByRole('checkbox').check();
  await expect(topicRow(manager, budgetEdited)).toHaveClass(/discussed/);
  await expect(
    topicRow(employee, budgetEdited).getByRole('checkbox'),
  ).toBeChecked({ timeout: 8000 });
  await expect(topicRow(employee, budgetEdited)).toHaveClass(/discussed/);

  // Both tick different topics at the same moment: the second save hits the
  // version conflict and is reapplied to the first one's list, so neither
  // tick is lost.
  await addTopic(employee, `${budget}-SECOND`);
  await expect(topicRow(manager, `${budget}-SECOND`)).toBeVisible({
    timeout: 8000,
  });
  await Promise.all([
    topicRow(employee, `${budget}-SECOND`).getByRole('checkbox').check(),
    topicRow(manager, hiring).getByRole('checkbox').check(),
  ]);
  for (const page of [employee, manager]) {
    await expect(
      topicRow(page, `${budget}-SECOND`).getByRole('checkbox'),
    ).toBeChecked({ timeout: 8000 });
    await expect(topicRow(page, hiring).getByRole('checkbox')).toBeChecked({
      timeout: 8000,
    });
  }
  // Unticking works too.
  await topicRow(employee, hiring).getByRole('checkbox').uncheck();
  await expect(topicRow(manager, hiring).getByRole('checkbox')).not.toBeChecked(
    { timeout: 8000 },
  );

  // The author deletes a topic, after confirming.
  await topicRow(manager, hiring)
    .getByRole('button', { name: 'Delete' })
    .click();
  await topicRow(manager, hiring)
    .getByRole('button', { name: 'Confirm delete' })
    .click();
  await expect(topicRow(manager, hiring)).toHaveCount(0);
  await expect(topicRow(employee, hiring)).toHaveCount(0, { timeout: 8000 });

  // A reload shows the same list: it was saved, not only shown.
  await employee.reload();
  await expect(topicsCard(employee).locator('.topic')).toHaveCount(3);
  await expect(
    topicRow(employee, budgetEdited).getByRole('checkbox'),
  ).toBeChecked();
  await expect(
    topicRow(employee, rotation).getByRole('checkbox'),
  ).not.toBeChecked();

  // The manager adds one more topic that the employee's tab never hears of
  // (its live updates are cut off), and the employee archives. The archive
  // is refused once, since the carried-forward topics were built from an
  // older list, and goes through with the current one: the late topic isn't
  // left behind.
  await employee.route('**/live-state', (route) => route.abort());
  const late = `E2E-TOPIC-LATE-${Date.now()}`;
  await addTopic(manager, late);
  const refusedArchive = employee.waitForResponse(
    (response) =>
      response.url().endsWith('/archive') && response.status() === 409,
  );
  await archive(employee);
  await refusedArchive;
  await expectArchived(employee);
  await employee.unroute('**/live-state');
  await expect(topicRow(employee, late)).toBeVisible();
  await expect(
    topicsCard(employee).getByPlaceholder('Add a topic…'),
  ).toHaveCount(0);
  await expect(topicsCard(employee).getByRole('button')).toHaveCount(0);
  await expect(
    topicRow(employee, rotation).getByRole('checkbox'),
  ).toBeDisabled();
  await expect(
    topicsCard(manager).getByPlaceholder('Add a topic…'),
  ).toHaveCount(0, { timeout: 8000 });
  await expect(topicsCard(manager).locator('.topic')).toHaveCount(4);

  // The next meeting starts with the two topics not ticked off, for both:
  // the archiving browser re-encrypted them under the new meeting's key.
  const successorUrl = await openSuccessor(employee, anketaUrl);
  await expect(topicsCard(employee).locator('.topic')).toHaveCount(2);
  await expect(topicRow(employee, late)).toBeVisible();
  await expect(
    topicRow(employee, rotation).getByRole('checkbox'),
  ).not.toBeChecked();
  await manager.goto(successorUrl);
  await expect(topicsCard(manager).locator('.topic')).toHaveCount(2);
  // Still the manager's own topic there, so still theirs to edit.
  await expect(
    topicRow(manager, rotation).getByRole('button', { name: 'Edit' }),
  ).toBeVisible();
  // And the carried list takes changes like any other.
  await topicRow(employee, rotation).getByRole('checkbox').check();
  await expect(topicRow(manager, rotation).getByRole('checkbox')).toBeChecked({
    timeout: 8000,
  });
});

/**
 * GitHub issue #206: new meetings no longer have the "What else to discuss"
 * blocks, which the topics list replaces. A meeting created before that keeps
 * the block and what was answered in it.
 */
test('a meeting from before the topics list keeps its "What else to discuss" block and answers', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-oldform');
  const managerEmail = uniqueEmail('manager-oldform');
  const employee = await activate(browser, createActivationLink(employeeEmail));
  const manager = await activate(browser, createActivationLink(managerEmail));
  const discussTitle = 'What else to discuss';

  const anketaUrl = await createAnketa(employee, managerEmail, 3);
  const employeeMySide = employee.locator('.side-card').first();
  await expect(questionBlock(employeeMySide, 'Mood')).toBeVisible();
  await expect(questionBlock(employeeMySide, discussTitle)).toHaveCount(0);

  setFormVersion(anketaUrl.split('/').pop()!, 2);
  await employee.reload();

  const discussBlock = questionBlock(employeeMySide, discussTitle);
  await expect(discussBlock).toBeVisible();
  const entry = `E2E-OLD-DISCUSS-${Date.now()}`;
  await discussBlock.getByPlaceholder('Add an entry…').fill(entry);
  await discussBlock.getByRole('button', { name: 'Add' }).click();
  await employeeMySide.getByRole('button', { name: 'Publish' }).click();
  await expect(employeeMySide.getByText('Published')).toBeVisible();

  // The counterpart reads it, and has their own block of the old form too.
  await manager.goto(anketaUrl);
  await expect(
    questionBlock(manager.locator('.side-card').nth(1), discussTitle),
  ).toContainText(entry);
  await expect(
    questionBlock(manager.locator('.side-card').first(), discussTitle),
  ).toBeVisible();
  // The topics list is there for an old meeting as well.
  await expect(topicsCard(manager)).toBeVisible();
});

/**
 * GitHub issue #203: the pair's permanent link, copied from a meeting's page
 * for a calendar event, opens the pair's open meeting for either of them, follows the chain to
 * the next cycle once that one is archived, and with nothing open offers to
 * schedule the next one with the colleague already chosen.
 */
test("a pair's calendar link follows the chain across cycles", async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-calendar-link');
  const managerEmail = uniqueEmail('manager-calendar-link');
  const employeeToken = createActivationLink(employeeEmail);
  const managerToken = createActivationLink(managerEmail);

  const employee = await activate(browser, employeeToken);
  const manager = await activate(browser, managerToken);

  const firstUrl = await createAnketa(employee, managerEmail, 3);

  await employee
    .context()
    .grantPermissions(['clipboard-read', 'clipboard-write']);
  await employee.getByRole('button', { name: 'Calendar link' }).click();
  await expect(
    employee.getByText('Link copied.', { exact: true }).last(),
  ).toBeVisible();
  await expect(
    employee.getByText('Paste this permanent link', { exact: false }),
  ).toBeVisible();
  const link = await employee.evaluate<string>(
    'navigator.clipboard.readText()',
  );
  expect(link).toMatch(
    /^http:\/\/localhost:5174\/pair\/[0-9a-f-]+\/[0-9a-f-]+$/,
  );

  // The open meeting, for both of them: the link names the pair.
  await employee.goto(link);
  await employee.waitForURL(firstUrl);
  await manager.goto(link);
  await manager.waitForURL(firstUrl);

  // Archived with a next meeting: the same link now opens the successor.
  await archive(employee);
  await expect(archiveButton(employee)).toHaveCount(0);
  await employee.goto(link);
  await employee.waitForURL(
    (url) =>
      /\/anketas\/[0-9a-f-]+$/.test(url.pathname) && url.href !== firstUrl,
  );
  const secondUrl = employee.url();
  await expect(archiveButton(employee)).toBeVisible();

  // The chain ends: the link shows the last meeting and offers the next one.
  await employee
    .getByRole('checkbox', { name: "Don't create the next meeting" })
    .check({ force: true });
  await archive(employee);
  await expect(archiveButton(employee)).toHaveCount(0);
  await employee.goto(link);
  await expect(
    employee.getByRole('heading', { name: /^No open 1:1 with / }),
  ).toBeVisible();
  await expect(
    employee.getByRole('link', { name: 'Open the last 1:1' }),
  ).toHaveAttribute('href', new URL(secondUrl).pathname);
  await employee.getByRole('button', { name: 'Schedule the next one' }).click();
  await employee.waitForURL('/anketas/new');
  await expect(
    employee.getByPlaceholder('Type a name or email to search…'),
  ).toHaveValue(
    new RegExp(managerEmail.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')),
  );
  // The colleague is preselected; the role isn't, though the pair has a
  // history (GitHub issue #251).
  for (const name of ['I lead this 1:1', 'leads this 1:1']) {
    await expect(employee.getByRole('radio', { name })).not.toBeChecked();
  }

  // A link to a pair the manager isn't part of.
  await manager.goto(
    '/pair/00000000-0000-7000-8000-000000000001/00000000-0000-7000-8000-000000000002',
  );
  await expect(
    manager.getByRole('heading', { name: 'No 1:1 behind this link' }),
  ).toBeVisible();
});

/**
 * GitHub issue #204: a meeting left open for longer than its period gets a
 * banner in the list, and its link opens the archive form with a next date
 * back on the pair's cadence instead of in the past.
 */
test('a meeting open for longer than its period gets a list banner leading to the archive form', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('employee-long-open');
  const managerEmail = uniqueEmail('manager-long-open');
  const employee = await activate(browser, createActivationLink(employeeEmail));
  await activate(browser, createActivationLink(managerEmail));

  // 20 days back, with the form's default weekly period: three periods on is
  // tomorrow, the first cadence date that isn't in the past.
  const anketaUrl = await createAnketa(employee, managerEmail, -20);
  await employee.goto('/');
  const banner = employee.locator('.long-open');
  await expect(banner).toContainText(managerEmail);
  await expect(banner).toContainText("isn't closed yet");
  await banner
    .getByRole('link', { name: 'Close and schedule the next one' })
    .click();
  await expect(employee.locator('#archive-heading')).toBeFocused();
  await expect(employee).toHaveURL(anketaUrl);
  const tomorrow = new Date();
  tomorrow.setDate(tomorrow.getDate() + 1);
  const dd = String(tomorrow.getDate()).padStart(2, '0');
  const mm = String(tomorrow.getMonth() + 1).padStart(2, '0');
  await expect(employee.locator('#next-meeting-date')).toHaveValue(
    `${dd}.${mm}.${tomorrow.getFullYear()}`,
  );
});
