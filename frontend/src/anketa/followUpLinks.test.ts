import { describe, expect, it } from 'vitest';
import { repoFile } from '../testRepoFile';
import { FOLLOW_UP_HASH } from './followUpLinks';

describe('FOLLOW_UP_HASH', () => {
  it('matches the fragments the follow-up email links carry', () => {
    const php = repoFile('backend/src/Notification/AnketaNotifier.php');

    expect(php).toContain(`'%close_url%' => $url.'${FOLLOW_UP_HASH.close}'`);
    expect(php).toContain(
      `'%reschedule_url%' => $url.'${FOLLOW_UP_HASH.reschedule}'`,
    );
  });
});
