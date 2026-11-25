// A cloaked click's public id, as the edge builds it (Prosper202\Click\PublicClickId).
// Web Crypto only, as a Cloudflare Worker has it, and no arithmetic above 2^32:
// the id is 19 digits, past what a JavaScript number holds exactly, so it is
// built and compared as text and never parsed as a number.
// Run as `node reference.mjs <vectors.json>`: prints {key_hex, click_id, public_id}
// for every vector, which PublicClickIdTest compares with the PHP answers.

export const MESSAGE_PREFIX = 'p202-click-public-id:v1:';
export const MAX_CLICK_ID = 999999999999;

export async function publicClickId(clickId, keyBytes) {
  if (!Number.isSafeInteger(clickId) || clickId < 1 || clickId > MAX_CLICK_ID) {
    throw new RangeError('A click id for a public id is 1 to ' + MAX_CLICK_ID);
  }
  if (keyBytes.byteLength !== 32) {
    throw new RangeError('The click public id key must be 32 bytes');
  }
  const key = await crypto.subtle.importKey('raw', keyBytes, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const mac = new DataView(await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(MESSAGE_PREFIX + String(clickId))));
  const suffix = mac.getUint32(0, false) % 1000000;
  return '1' + String(clickId).padStart(12, '0') + String(suffix).padStart(6, '0');
}

function hexBytes(hex) {
  return Uint8Array.from(hex.match(/../g), (pair) => parseInt(pair, 16));
}

if (typeof process !== 'undefined' && process.argv?.[2] !== undefined) {
  const { readFile } = await import('node:fs/promises');
  const vectors = JSON.parse(await readFile(process.argv[2], 'utf8')).vectors;
  const out = [];
  for (const v of vectors) {
    out.push({ key_hex: v.key_hex, click_id: v.click_id, public_id: await publicClickId(v.click_id, hexBytes(v.key_hex)) });
  }
  process.stdout.write(JSON.stringify(out) + '\n');
}
