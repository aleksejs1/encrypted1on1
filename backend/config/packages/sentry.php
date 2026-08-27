<?php

use App\EventListener\SentryBeforeSendFilter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    // Sentry's own Options::validateDsnOption() treats an empty string the same as
    // null/false: it disables sending entirely rather than erroring. So an unset
    // SENTRY_DSN (self-hosted deployments, by default) means this bundle captures
    // nothing — no separate on/off flag needed. Only the cloud deployment sets a real
    // DSN today (docs/deployment.md); self-hosted operators can opt in the same way.
    $container->extension('sentry', [
        'dsn' => '%env(SENTRY_DSN)%',
        'options' => [
            'before_send' => SentryBeforeSendFilter::class,
            // Same filter, wired for transaction/tracing events too — the SDK sends
            // those through a separate callback from error events. Dormant today
            // (traces_sample_rate is never set, so tracing stays off), but if that's
            // ever turned on, a sampled transaction for an activation/password-reset
            // route would otherwise carry the unredacted token in
            // contexts.trace.data.http.url and bypass before_send entirely. The
            // filter's exception check simply no-ops for a transaction event (no
            // exception in the hint), so reusing it here is safe as well as cheap.
            'before_send_transaction' => SentryBeforeSendFilter::class,
            // Controllers here (AuthController, CompanyController) read login/password-
            // change/account-recovery secrets straight out of the request body — see
            // e.g. AuthController's authKey/newEncryptedPrivateKey fields. Sentry's own
            // default ('medium', up to 10KB) would attach that body verbatim to any
            // reported event regardless of send_default_pii, which only governs
            // IP/cookies/user info, not the request payload. 'none' means Sentry never
            // sees a request body at all — never sending secrets somewhere outweighs the
            // debugging convenience of seeing what was posted.
            'max_request_body_size' => 'none',
            // Already the SDK's own default (governs IP/cookies/user data, separately
            // from max_request_body_size above) — pinned explicitly rather than left
            // implicit, so this file states the full PII posture in one place and
            // doesn't silently change if a future SDK major version flips the default.
            'send_default_pii' => false,
            // Sentry being slow/unreachable must never make an already-erroring
            // request slower for the user than necessary — this fires synchronously
            // during exception handling, before the response is sent. Tighter than the
            // SDK's own defaults (2s connect / 5s total): this app has no message
            // queue or background worker to hand the send off to (see architecture.md),
            // so a bounded worst case here is the only mitigation available.
            'http_connect_timeout' => 1,
            'http_timeout' => 2,
        ],
    ]);
};
