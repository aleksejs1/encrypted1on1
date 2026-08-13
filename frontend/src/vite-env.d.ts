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
}
