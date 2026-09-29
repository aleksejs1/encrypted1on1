<?php

namespace App\Command;

/** SendRemindersCommand's tally across its one or two passes (GitHub issue #167). */
final class ReminderRunResult
{
    /** Anketas reminded. */
    public int $count = 0;

    /** Anketas that failed and were released, so a same-day rerun retries them. */
    public int $failed = 0;

    /** Anketas that failed and couldn't be released: no rerun retries them. */
    public int $stuck = 0;

    /** Days whose select failed, so none of their meetings were looked at. */
    public int $failedDays = 0;

    public ?\Throwable $firstError = null;
}
