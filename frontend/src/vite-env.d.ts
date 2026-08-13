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
}
