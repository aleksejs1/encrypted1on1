import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const REPO_ROOT = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../../..',
);
const COMPOSE_FILE = path.join(REPO_ROOT, 'docker-compose.e2e.yml');

/**
 * Creates a real account-activation link via the same CLI used to bootstrap
 * real accounts (bin/console app:create-activation-link) — mirrors how the
 * backend's own tests (ApiTestCase::activateUser(),
 * PrivacyBlackBoxTest::activateUserWithRealKeypair()) provision accounts:
 * the token is issued by real backend code, but the *activation itself* is
 * still driven through the real /activate/:token UI by the test.
 *
 * Requires the isolated e2e stack (docker-compose.e2e.yml) to already be
 * running — see `make e2e-up`.
 */
export function createActivationLink(email: string, admin = false): string {
  const output = execFileSync(
    'docker',
    [
      'compose',
      '-f',
      COMPOSE_FILE,
      'exec',
      '-T',
      'backend',
      'php',
      'bin/console',
      'app:create-activation-link',
      email,
      ...(admin ? ['--admin'] : []),
      '--no-ansi',
    ],
    { encoding: 'utf-8' },
  );

  const match = output.match(/\/activate\/([a-f0-9]{64})/);
  if (!match) {
    throw new Error(
      `Could not find an activation token in CLI output:\n${output}`,
    );
  }
  return match[1];
}

/** A per-run-unique email, so repeated local e2e runs never collide with earlier ones. */
export function uniqueEmail(label: string): string {
  return `e2e-${Date.now()}-${Math.random().toString(36).slice(2, 8)}-${label}@example.com`;
}

/**
 * Same idea as createActivationLink(), one step later in the account
 * lifecycle: issues a real PasswordResetToken via
 * app:create-password-reset-link (the same token POST /api/password-reset
 * would issue) without a real email round-trip — the e2e stack runs with
 * MAILER_DSN=null://null and no Mailpit.
 */
export function createPasswordResetLink(email: string): string {
  const output = execFileSync(
    'docker',
    [
      'compose',
      '-f',
      COMPOSE_FILE,
      'exec',
      '-T',
      'backend',
      'php',
      'bin/console',
      'app:create-password-reset-link',
      email,
      '--no-ansi',
    ],
    { encoding: 'utf-8' },
  );

  const match = output.match(/\/reset-password\/([a-f0-9]{64})/);
  if (!match) {
    throw new Error(
      `Could not find a password-reset token in CLI output:\n${output}`,
    );
  }
  return match[1];
}

function runSql(sql: string): void {
  execFileSync(
    'docker',
    [
      'compose',
      '-f',
      COMPOSE_FILE,
      'exec',
      '-T',
      'backend',
      'php',
      'bin/console',
      'dbal:run-sql',
      sql,
      '--no-ansi',
    ],
    { encoding: 'utf-8' },
  );
}

function sqlString(value: string): string {
  return `'${value.replaceAll("'", "''")}'`;
}

/**
 * GitHub issue #169: turns the token createActivationLink() issued for
 * `email` into an expired invite from `inviterEmail` — the InviteRecord
 * InviteController::create() would have written, and an expiry an hour ago.
 * Done in SQL because an invite sent through the UI only ever reaches the
 * invitee by email, which the e2e stack doesn't deliver.
 */
export function expireAsInvite(email: string, inviterEmail: string): void {
  runSql(
    `UPDATE activation_tokens SET expiresAt = datetime('now', '-1 hour') WHERE email = ${sqlString(email)}`,
  );
  runSql(
    'INSERT INTO invite_records (id, email, createdAt, expiresAt, company_id, invitedBy_id)' +
      ` SELECT t.id, t.email, datetime('now', '-25 hours'), t.expiresAt, t.company_id, u.id` +
      ` FROM activation_tokens t JOIN users u ON u.email = ${sqlString(inviterEmail)}` +
      ` WHERE t.email = ${sqlString(email)}`,
  );
}

/**
 * GitHub issue #206: makes an existing meeting one created at an older form
 * version, the way every meeting from before a form change is stored. Done in
 * SQL because the server stamps the current version on everything it creates.
 */
export function setFormVersion(anketaId: string, formVersion: number): void {
  if (!/^[0-9a-f-]{36}$/.test(anketaId) || !Number.isInteger(formVersion)) {
    throw new Error('setFormVersion: unexpected anketa id or form version');
  }
  runSql(
    `UPDATE anketas SET formVersion = ${formVersion} WHERE id = ${sqlString(anketaId)}`,
  );
}
