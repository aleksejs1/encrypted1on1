/**
 * A timeout for a request carrying or bringing back `bodyLength` characters:
 * `fetch` has none of its own, and a request into a dead connection (a
 * dropped VPN, a captive portal) hangs instead of failing. Scaled with the
 * body, so a large one on a slow link isn't cut off. It rejects with a
 * TimeoutError, which api/client.ts reports as a lost connection. Whether a
 * write that timed out reached the server is then unknown, and its caller
 * has to allow for both.
 */
export function requestTimeout(bodyLength: number): AbortSignal {
  return AbortSignal.timeout(20_000 + 2 * Math.ceil(bodyLength / 1000) * 100);
}
