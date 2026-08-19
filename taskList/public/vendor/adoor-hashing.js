/**
 * VENDORED — do not edit by hand.
 *
 * This is the compiled output of the official Adoor TypeScript SDK
 * (masenu-pro/sdk/typescript/src/hashing.ts → dist/hashing.js). It is the SAME
 * normalization + HMAC-SHA256 the platform, the PHP SDK, and the Python SDK use,
 * so a hash produced here joins the network byte-for-byte. The browser demo
 * imports it instead of re-implementing hashing, so there is one source of truth.
 *
 * To update: `cd masenu-pro/sdk/typescript && npm run build` then copy
 * `dist/hashing.js` over this file. The pinned parity vectors in
 * adoor-selftest.js will catch any drift on page load.
 */
/** The identifier kinds Adoor understands. */
export const KINDS = [
    "msisdn",
    "momo_wallet",
    "bank_account",
    "ghana_card",
    "passport",
    "tin",
    "email",
    "device_id",
    "ip",
    "social_handle",
    "url",
];
const NON_DIGIT = /\D/g;
const SPACE_HYPHEN = /[\s-]/g;
/**
 * Canonicalize an identifier before hashing. Same rules the network uses, so
 * the identical real-world identifier always produces the identical hash.
 */
export function normalize(kind, value) {
    const v = value.trim();
    switch (kind) {
        case "msisdn":
        case "momo_wallet":
        case "bank_account": {
            let digits = v.replace(NON_DIGIT, "");
            // Ghana local 0XXXXXXXXX -> E.164-style 233XXXXXXXXX.
            if (kind === "msisdn" && digits.startsWith("0") && digits.length === 10) {
                digits = "233" + digits.slice(1);
            }
            return digits;
        }
        case "email":
        case "social_handle":
            return v.toLowerCase().replace(/^@+/, "");
        case "url":
            return v.toLowerCase().replace(/\/+$/, "");
        case "ghana_card":
        case "passport":
        case "tin":
            return v.replace(SPACE_HYPHEN, "").toUpperCase();
        default:
            return v.toLowerCase();
    }
}
function toHex(buf) {
    const bytes = new Uint8Array(buf);
    let hex = "";
    for (let i = 0; i < bytes.length; i++) {
        hex += bytes[i].toString(16).padStart(2, "0");
    }
    return hex;
}
/**
 * HMAC-SHA256(normalize(value), pepper) as lowercase hex — the consortium
 * `value_hash`. The pepper comes from your onboarding pack; keep it secret.
 */
export async function hashIdentifier(kind, value, pepper) {
    if (!KINDS.includes(kind)) {
        throw new Error(`unknown identifier kind: ${kind}`);
    }
    const enc = new TextEncoder();
    const key = await crypto.subtle.importKey("raw", enc.encode(pepper), { name: "HMAC", hash: "SHA-256" }, false, ["sign"]);
    const sig = await crypto.subtle.sign("HMAC", key, enc.encode(normalize(kind, value)));
    return toHex(sig);
}
/** SHA-256 hex of an arbitrary string — used for payload-digest idempotency keys. */
export async function sha256Hex(text) {
    const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(text));
    return toHex(digest);
}
