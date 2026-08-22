const ARGON2ID_PROFILES = ['interactive', 'moderate', 'sensitive'] as const;
export type Argon2idProfile = (typeof ARGON2ID_PROFILES)[number];

/**
 * Validates VITE_ARGON2ID_PROFILE (a build-time value baked into the static
 * bundle — see docker/prod/app.Dockerfile). Falls back to 'interactive'
 * (today's fixed behavior) on anything missing/invalid, same silent-default
 * convention already used for a bad/missing User.locale — a misconfigured
 * env var shouldn't hard-fail every login.
 *
 * WARNING: this must be picked once, before any real user registers, and
 * never changed afterwards on a running instance — see docs/deployment.md.
 * Both the login "auth key" and the master key that unwraps the private key
 * are derived from this profile; changing it locks every existing account
 * out irrecoverably (wrong auth key, and the stored encrypted private key
 * no longer unwraps even after a successful login).
 */
export function resolveArgon2Profile(raw: string | undefined): Argon2idProfile {
  return (ARGON2ID_PROFILES as readonly string[]).includes(raw ?? '')
    ? (raw as Argon2idProfile)
    : 'interactive';
}
