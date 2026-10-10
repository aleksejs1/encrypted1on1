/**
 * The URL fragments of the follow-up email's two links (GitHub issue #202),
 * written by the backend's AnketaNotifier::notifyMeetingFollowUp(): one lands
 * on the archive form, the other on the date field that moves the meeting.
 * Neither names an element id, so the browser doesn't scroll by itself.
 */
export const FOLLOW_UP_HASH = {
  close: '#close',
  reschedule: '#reschedule',
} as const;

/**
 * Drops the fragment once its link has been followed, so a reload, or Back to
 * this page, doesn't scroll or take focus again.
 */
export function clearFollowUpHash(): void {
  history.replaceState(
    history.state,
    '',
    window.location.pathname + window.location.search,
  );
}

/** The id of whichever "move the meeting" date field AnketaHeader shows. */
export const RESCHEDULE_DATE_ID = 'reschedule-date';
