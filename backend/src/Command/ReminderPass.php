<?php

namespace App\Command;

/** What one pass of SendRemindersCommand over a day's meetings sends. */
enum ReminderPass
{
    /** The day-before reminder. */
    case Tomorrow;

    /** Friday's reminder for a Monday meeting (GitHub issue #167). */
    case Monday;

    /** The "did your 1:1 happen?" email after a meeting nobody closed (GitHub issue #202). */
    case FollowUp;
}
