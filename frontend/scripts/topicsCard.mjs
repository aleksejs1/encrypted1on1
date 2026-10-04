/**
 * Playwright helpers for the meeting page's shared "Topics to discuss" card
 * (GitHub issue #206), shared by generate-demo-fixture.mjs and
 * generate-doc-screenshots.mjs like fillDateInput.mjs: since form version 3
 * the list holds what the "What else to discuss" question did.
 */
export function topicsCard(page) {
  return page.locator('section.card', {
    has: page.getByRole('heading', { name: 'Topics to discuss' }),
  });
}

/** Resolves once a topics save for `anketaId` has succeeded. */
export function topicsSaved(page, anketaId) {
  return page.waitForResponse(
    (res) =>
      res.request().method() === 'PUT' &&
      res.url().endsWith(`/api/anketas/${anketaId}/topics`) &&
      res.ok(),
  );
}

export async function addTopic(page, anketaId, text) {
  const card = topicsCard(page);
  await card.getByPlaceholder('Add a topic…').fill(text);
  await Promise.all([
    topicsSaved(page, anketaId),
    card.getByRole('button', { name: 'Add', exact: true }).click(),
  ]);
  await card.locator('.topic', { hasText: text }).waitFor();
}

/** The outcomes card's add form: the topics card has an Add button too. */
export function outcomeForm(page) {
  return page.locator('form', { has: page.getByPlaceholder(/outcome/i) });
}
