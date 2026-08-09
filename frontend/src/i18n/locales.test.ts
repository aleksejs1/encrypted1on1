import { describe, expect, it } from 'vitest';
import en from './locales/en.json';
import ru from './locales/ru.json';
import lv from './locales/lv.json';
import es from './locales/es.json';

/**
 * Cheap substitute for the "lint check for missing keys" the spec calls
 * optional (Phase 6h plan) — a mismatch here means a locale would silently
 * fall back to English at runtime instead of failing the build.
 */
function flattenKeys(obj: unknown, prefix = ''): string[] {
  if (typeof obj !== 'object' || obj === null) return [prefix];
  return Object.entries(obj).flatMap(([key, value]) => flattenKeys(value, prefix ? `${prefix}.${key}` : key));
}

const locales: Record<string, unknown> = { en, ru, lv, es };
const englishKeys = flattenKeys(en).sort();

describe('locale files', () => {
  for (const [code, messages] of Object.entries(locales)) {
    it(`${code}.json has exactly the same keys as en.json`, () => {
      expect(flattenKeys(messages).sort()).toEqual(englishKeys);
    });

    it(`${code}.json has no empty string values`, () => {
      const empties = flattenKeys(messages).filter((key) => {
        const value = key.split('.').reduce<unknown>((obj, part) => (obj as Record<string, unknown>)?.[part], messages);
        return value === '';
      });
      expect(empties).toEqual([]);
    });
  }
});
