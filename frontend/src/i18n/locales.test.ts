import { describe, expect, it } from 'vitest';
import en from './locales/en.json';
import ru from './locales/ru.json';
import lv from './locales/lv.json';
import es from './locales/es.json';
import de from './locales/de.json';
import fr from './locales/fr.json';
import { messageAt } from './testUtils';

/**
 * Cheap substitute for the "lint check for missing keys" the spec calls
 * optional (Phase 6h plan) — a mismatch here means a locale would silently
 * fall back to English at runtime instead of failing the build.
 */
function flattenKeys(obj: unknown, prefix = ''): string[] {
  if (typeof obj !== 'object' || obj === null) return [prefix];
  return Object.entries(obj).flatMap(([key, value]) =>
    flattenKeys(value, prefix ? `${prefix}.${key}` : key),
  );
}

const locales: Record<string, unknown> = { en, ru, lv, es, de, fr };
const englishKeys = flattenKeys(en).sort();
const OLD_TERMS = /anket|анкет|cuestionario|fragebogen|questionnaire/i;

describe('locale files', () => {
  for (const [code, messages] of Object.entries(locales)) {
    it(`${code}.json has exactly the same keys as en.json`, () => {
      expect(flattenKeys(messages).sort()).toEqual(englishKeys);
    });

    it(`${code}.json has no empty string values`, () => {
      const empties = flattenKeys(messages).filter((key) => {
        return messageAt(messages, key) === '';
      });
      expect(empties).toEqual([]);
    });

    // GitHub issue #195: the product's object is a "1:1" in every locale;
    // "anketa" survives only in keys, identifiers and routes. The other
    // words are the questionnaire terms es/de/fr used before the rename.
    it(`${code}.json never calls a 1:1 an "anketa" in its text`, () => {
      const offenders = flattenKeys(messages).filter((key) =>
        OLD_TERMS.test(String(messageAt(messages, key))),
      );
      expect(offenders).toEqual([]);
    });
  }
});
