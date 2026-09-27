/// <reference types="node" />
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

/**
 * A file of this repository, outside the frontend package, as text — for
 * tests that cross-check a frontend copy against its backend source (the
 * template definition cases, PHP constants). Needs the whole repo checked
 * out, as CI has it.
 */
export function repoFile(path: string): string {
  return readFileSync(
    fileURLToPath(new URL(`../../${path}`, import.meta.url)),
    'utf8',
  );
}
