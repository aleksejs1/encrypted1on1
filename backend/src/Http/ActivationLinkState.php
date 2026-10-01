<?php

namespace App\Http;

/**
 * What an activation link means for the person holding it (GitHub issue #169), as
 * decided by ActivationController::linkState(). For the expired states the value is
 * the wire format, the `renewal` next to `code: expired`, mirrored by
 * frontend/src/activationLink.ts; linkStateResponse() maps the other three to their
 * own status and `code`.
 */
enum ActivationLinkState: string
{
    /** No such token: never issued, or pruned RETENTION_DAYS_AFTER_EXPIRY days after it expired. */
    case Invalid = 'invalid';
    /** Used, or its address has an account by now (through another invite to it, still usable or not). */
    case AlreadyActivated = 'already_activated';
    /** Not expired or used, and no account has the address yet. */
    case Usable = 'usable';
    /** Expired, but a newer link to the same address in the same company is still usable. */
    case Reissued = 'reissued';
    /** Expired self-registration: signing up again issues a new link. */
    case Signup = 'signup';
    /** Expired cloud company creation (CLOUD_MODE): starting it again issues a new link. */
    case CreateCompany = 'create_company';
    /**
     * Expired, and nobody to ask: the CLI bootstrap, a self-registration whose company
     * has since left REGISTRATION_MODE=domain (or is on Cloud), or an invite with no
     * active inviter or admin left to re-send it.
     */
    case None = 'none';
    /** Expired invite, renewal already requested within InviteRecord::RENEWAL_COOLDOWN_HOURS. */
    case Requested = 'requested';
    /** Expired invite, renewal can be requested. */
    case Available = 'available';
}
