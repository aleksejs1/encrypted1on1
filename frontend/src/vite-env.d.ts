/// <reference types="vite/client" />

interface ImportMetaEnv {
  /**
   * Which argon2id cost profile to derive login/master keys with (see
   * src/crypto/argon2Profile.ts) — baked into the bundle at build time,
   * set via docker/prod/app.Dockerfile's ARGON2ID_PROFILE build arg.
   * Unset in dev, which is exactly why resolveArgon2Profile() defaults to
   * 'interactive' rather than requiring this to always be set.
   */
  readonly VITE_ARGON2ID_PROFILE?: string;

  /**
   * The deploying company's own privacy policy/legal notice URL, shown as a
   * link in the app's footer — baked into the bundle at build time, set via
   * docker/prod/app.Dockerfile's PRIVACY_POLICY_URL build arg. Empty/unset
   * means no footer link at all (an operator has to opt in), not a broken
   * or placeholder link.
   */
  readonly VITE_PRIVACY_POLICY_URL?: string;

  /**
   * Shows a "Try the live demo" button on the login page when set to
   * exactly "true" — baked into the bundle at build time, set via
   * docker/prod/app.Dockerfile's DEMO_MODE build arg. See
   * private/demo-mode-plan.md (not tracked in git) and
   * bin/console app:reset-demo-data. Unset/anything else means the button
   * never renders — off by default, matching every other opt-in feature
   * in this app.
   */
  readonly VITE_DEMO_MODE?: string;

  /**
   * Short git commit hash the running image was built from — baked into the
   * bundle at build time, set via docker/prod/app.Dockerfile's GIT_SHA build
   * arg. docker/prod/deploy.sh computes this automatically for a local
   * build-from-source deploy; GHCR release images (docker-release.yml)
   * never set it, so it's unset there. Unset means the footer just shows
   * the version with no hash.
   */
  readonly VITE_GIT_SHA?: string;

  /**
   * Shows the app version (and commit hash, if VITE_GIT_SHA is set) in the
   * footer when set to exactly "true" — baked into the bundle at build time,
   * set via docker/prod/app.Dockerfile's SHOW_VERSION build arg. Off by
   * default, same as every other opt-in footer/login feature in this app.
   */
  readonly VITE_SHOW_VERSION?: string;
}

/**
 * The frontend's own version (frontend/package.json's "version" field) —
 * injected via `define` in vite.config.ts/vite.config.e2e.ts, not an env var,
 * so it can't drift out of sync with package.json.
 */
declare const __APP_VERSION__: string;
