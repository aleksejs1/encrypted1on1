import { describe, expect, it } from 'vitest';
import { EMOJI_SHORTCODES } from './emojiShortcodes';

describe('EMOJI_SHORTCODES', () => {
  it('holds the full table, GitHub and Slack names included', () => {
    expect(EMOJI_SHORTCODES.size).toBeGreaterThan(1900);
    expect(EMOJI_SHORTCODES.get('slightly_smiling_face')).toBe('🙂');
    expect(EMOJI_SHORTCODES.get('+1')).toBe('👍');
    expect(EMOJI_SHORTCODES.get('thinking')).toBe('🤔');
    expect(EMOJI_SHORTCODES.get('thinking_face')).toBe('🤔');
  });

  it('has only emoji as values', () => {
    // markdown.ts puts a value into the page as is, and the table is too long to audit by
    // reading. Besides pictographs: flags (regional indicators, or a black flag with tag
    // characters), keycaps (`#`, `*` or a digit, then the keycap mark), skin tones, and the
    // joiner and variation selector that hold a sequence together.
    const emojiOnly =
      /^[\p{Extended_Pictographic}\p{Regional_Indicator}\p{Emoji_Modifier}\u200D\uFE0F\u20E3\u{E0020}-\u{E007F}0-9#*]+$/u;
    for (const [name, emoji] of EMOJI_SHORTCODES) {
      expect(emoji, name).toMatch(emojiOnly);
      // A bare digit, `#` or `*` is only valid as a keycap.
      expect(emoji, name).not.toMatch(/[0-9#*](?!\uFE0F?\u20E3)/u);
      // Short enough to be one emoji, the longest being a family or a subdivision flag.
      expect(emoji.length, name).toBeLessThanOrEqual(16);
    }
  });
});
