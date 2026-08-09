import sodium from 'libsodium-wrappers-sumo';

let readyPromise: Promise<typeof sodium> | null = null;

/**
 * Lazily initializes libsodium once and memoizes the result. Everything in
 * this module tree should go through this rather than importing
 * libsodium-wrappers directly, so there's exactly one init path.
 */
export function getSodium(): Promise<typeof sodium> {
  if (!readyPromise) {
    readyPromise = sodium.ready.then(() => sodium);
  }
  return readyPromise;
}
