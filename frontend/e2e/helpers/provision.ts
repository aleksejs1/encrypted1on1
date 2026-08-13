import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const COMPOSE_FILE = path.join(REPO_ROOT, 'docker-compose.dev.yml');

/**
 * Creates a real account-activation link via the same CLI used to bootstrap
 * real accounts (bin/console app:create-activation-link) — mirrors how the
 * backend's own tests (ApiTestCase::activateUser(),
 * PrivacyBlackBoxTest::activateUserWithRealKeypair()) provision accounts:
 * the token is issued by real backend code, but the *activation itself* is
 * still driven through the real /activate/:token UI by the test.
 *
 * Requires the dev stack (docker-compose.dev.yml) to already be running.
 */
export function createActivationLink(email: string): string {
  const output = execFileSync(
    'docker',
    ['compose', '-f', COMPOSE_FILE, 'exec', '-T', 'backend', 'php', 'bin/console', 'app:create-activation-link', email, '--no-ansi'],
    { encoding: 'utf-8' },
  );

  const match = output.match(/\/activate\/([a-f0-9]{64})/);
  if (!match) {
    throw new Error(`Could not find an activation token in CLI output:\n${output}`);
  }
  return match[1];
}

/** A per-run-unique email, so repeated local e2e runs never collide with earlier ones. */
export function uniqueEmail(label: string): string {
  return `e2e-${Date.now()}-${Math.random().toString(36).slice(2, 8)}-${label}@example.com`;
}
