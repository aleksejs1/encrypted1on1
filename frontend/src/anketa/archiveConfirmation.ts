/**
 * What closing (archiving) a meeting asks the person to confirm first (GitHub
 * issue #229). Archiving is one-way and ends publishing for both sides, so it
 * is never a single click, and never goes ahead over my own unpublished
 * answers: those are published first.
 */
export interface ArchiveConfirmation {
  /** My side has unpublished answers: closing publishes them first. */
  publishFirst: boolean;
  /**
   * Whose answers the closed meeting will have none of. A side published by
   * `publishFirst` counts as published.
   */
  unpublished: 'nobody' | 'me' | 'counterpart' | 'both';
}

export function archiveConfirmation({
  myPublished,
  counterpartPublished,
  myDraftHasAnswers,
}: {
  myPublished: boolean;
  counterpartPublished: boolean;
  /** Ignored once my side is published: there is no draft then. */
  myDraftHasAnswers: boolean;
}): ArchiveConfirmation {
  const publishFirst = !myPublished && myDraftHasAnswers;
  const mine = myPublished || publishFirst;
  return {
    publishFirst,
    unpublished: mine
      ? counterpartPublished
        ? 'nobody'
        : 'counterpart'
      : counterpartPublished
        ? 'me'
        : 'both',
  };
}
