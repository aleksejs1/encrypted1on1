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

/** The id of whichever "move the meeting" date field AnketaHeader shows. */
export const RESCHEDULE_DATE_ID = 'reschedule-date';
