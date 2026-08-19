/**
 * Browser-side parity tripwire. Imports the vendored SDK hashing and asserts the
 * SAME pinned vectors the PHP/Python/TS SDKs check in CI. If the vendored file
 * ever drifts from the network's normalization, this fails loudly in the console
 * on page load — you find out immediately, not when a member's hashes stop
 * joining. Import it for side effect: `import '/vendor/adoor-selftest.js'`.
 */
import { hashIdentifier } from '/vendor/adoor-hashing.js';

const PEPPER = 'dev-only-pepper-not-for-production';
const VECTORS = [
  ['msisdn', '0559000911', '32ec24cab82324b404e03b0102fd6f2a756cbde558cb8d2f3fa5b15243095305'],
  ['msisdn', '0244123456', 'fe84352e6183e1246c57979f007d7a2b1cab66d64a329452a18748fb3b6e666d'],
  ['ghana_card', 'GHA-200555666-1', '3ceeddeadee5be23e610d1a174400875816042fd4dfbeb8f8e5103bbd86b5f1b'],
];

(async () => {
  let ok = 0;
  for (const [kind, value, expected] of VECTORS) {
    const got = await hashIdentifier(kind, value, PEPPER);
    if (got === expected) {
      ok++;
    } else {
      console.error(`[adoor parity] DRIFT on ${kind} "${value}": got ${got}, expected ${expected}. ` +
        'The vendored hashing has diverged from the SDK — re-copy dist/hashing.js.');
    }
  }
  if (ok === VECTORS.length) {
    console.info(`[adoor parity] ✓ ${ok}/${VECTORS.length} vectors match the network (edge hashing is canonical).`);
  }
})();
