import { getSodium } from './sodium';

/** Base64 via libsodium's own helpers, not a hand-rolled/btoa path — one encoding path for binary key material. */
export async function toBase64(bytes: Uint8Array): Promise<string> {
  const sodium = await getSodium();
  return sodium.to_base64(bytes, sodium.base64_variants.ORIGINAL);
}

export async function fromBase64(b64: string): Promise<Uint8Array> {
  const sodium = await getSodium();
  return sodium.from_base64(b64, sodium.base64_variants.ORIGINAL);
}
