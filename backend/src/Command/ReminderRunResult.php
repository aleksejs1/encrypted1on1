<?php

namespace App\Command;

/** SendRemindersCommand's tally across its passes (GitHub issues #167, #202). */
final class ReminderRunResult
{
    /** Anketas reminded. */
    public int $count = 0;

    /** Anketas followed up after their meeting day (GitHub issue #202). */
    public int $followUps = 0;

    /** Anketas that failed and were released, so a same-day rerun retries them. */
    public int $failed = 0;

    /** Anketas that failed and couldn't be released: no rerun retries them. */
    public int $stuck = 0;

    /** Days whose select failed, so none of their meetings were looked at. */
    public int $failedDays = 0;

    public ?\Throwable $firstError = null;

    public function countProcessed(bool $followUp): void
    {
        if ($followUp) {
            ++$this->followUps;
        } else {
            ++$this->count;
        }
    }

    /** @param bool $released false if the claim couldn't be released, so nothing retries it */
    public function countFailed(bool $released): void
    {
        if ($released) {
            ++$this->failed;
        } else {
            ++$this->stuck;
        }
    }

    public function summary(): string
    {
        // "Processed", not "sent": a participant who opted out gets no email.
        $message = sprintf('Processed reminders for %d anketa(s) and follow-ups for %d.', $this->count, $this->followUps);
        if ($this->failedDays > 0) {
            $message .= sprintf(' Selecting %d day(s) of meetings failed, so none of them were looked at; rerun today.', $this->failedDays);
        }
        if ($this->failed > 0) {
            $message .= sprintf(' %d failed and stay due; rerun today to retry them.', $this->failed);
        }
        if ($this->stuck > 0) {
            $message .= sprintf(' %d failed and couldn\'t be released, so no rerun retries them; see the errors above.', $this->stuck);
        }

        return $message;
    }
}
