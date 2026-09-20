import { createHmac } from 'node:crypto';

const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32Decode(secret: string): Buffer {
  const normalized = secret.replace(/=+$/g, '').toUpperCase();
  let bits = '';
  for (const char of normalized) {
    const value = BASE32.indexOf(char);
    if (value < 0) {
      continue;
    }
    bits += value.toString(2).padStart(5, '0');
  }

  const bytes: number[] = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) {
    bytes.push(Number.parseInt(bits.slice(i, i + 8), 2));
  }

  return Buffer.from(bytes);
}

/** RFC 6238 TOTP (SHA-1, 6 digits, 30s) matching the Core API verifier. */
export function totpCode(secret: string, nowMs = Date.now()): string {
  const counter = Math.floor(nowMs / 1000 / 30);
  const message = Buffer.alloc(8);
  message.writeUInt32BE(Math.floor(counter / 0x100000000), 0);
  message.writeUInt32BE(counter >>> 0, 4);
  const hmac = createHmac('sha1', base32Decode(secret)).update(message).digest();
  const offset = hmac[hmac.length - 1]! & 0x0f;
  const binary =
    ((hmac[offset]! & 0x7f) << 24) |
    ((hmac[offset + 1]! & 0xff) << 16) |
    ((hmac[offset + 2]! & 0xff) << 8) |
    (hmac[offset + 3]! & 0xff);

  return String(binary % 1_000_000).padStart(6, '0');
}
