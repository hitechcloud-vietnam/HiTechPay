import { createHmac, timingSafeEqual } from 'node:crypto';
import { HiTechPaySignatureVerificationError } from './errors';
import type { WebhookEvent } from './types';

export const SIGNATURE_HEADER_512 = 'x-webhook-signature-512';
export const SIGNATURE_HEADER_256 = 'x-webhook-signature-256';

export type RawBody = string | Uint8Array;
export type HeaderBag =
  | Record<string, string | string[] | undefined>
  | { get(name: string): string | null };

/**
 * Either the signature value itself (hex string from `x-webhook-signature-512`
 * or `x-webhook-signature-256`) or the full request headers object
 * (Node `IncomingMessage.headers`, Express `req.headers`, or a Fetch `Headers`).
 */
export type SignatureInput = string | string[] | HeaderBag | undefined | null;

type Algorithm = 'sha512' | 'sha256';

function toBuffer(body: RawBody): Buffer {
  if (typeof body === 'string') return Buffer.from(body, 'utf8');
  if (body instanceof Uint8Array) return Buffer.from(body.buffer, body.byteOffset, body.byteLength);
  throw new TypeError('HiTechPay webhooks: rawBody must be a string, Buffer or Uint8Array (the unparsed request body)');
}

function readHeader(headers: HeaderBag, name: string): string | undefined {
  if (typeof (headers as { get?: unknown }).get === 'function') {
    const v = (headers as { get(n: string): string | null }).get(name);
    return v ?? undefined;
  }
  const bag = headers as Record<string, string | string[] | undefined>;
  for (const key of Object.keys(bag)) {
    if (key.toLowerCase() === name) {
      const v = bag[key];
      return Array.isArray(v) ? v[0] : v;
    }
  }
  return undefined;
}

/** Resolve the signature + algorithm from a header value or a headers object. */
function resolveSignature(input: SignatureInput): { signature: string; algorithms: Algorithm[] } | undefined {
  if (input == null) return undefined;
  if (Array.isArray(input)) input = input[0];
  if (typeof input === 'string') {
    const sig = input.trim();
    if (!sig) return undefined;
    // Infer the algorithm from the hex length; fall back to trying both.
    if (sig.length === 128) return { signature: sig, algorithms: ['sha512'] };
    if (sig.length === 64) return { signature: sig, algorithms: ['sha256'] };
    return { signature: sig, algorithms: ['sha512', 'sha256'] };
  }
  if (typeof input !== 'object') return undefined;
  const s512 = readHeader(input as HeaderBag, SIGNATURE_HEADER_512);
  if (s512 && s512.trim()) return { signature: s512.trim(), algorithms: ['sha512'] };
  const s256 = readHeader(input as HeaderBag, SIGNATURE_HEADER_256);
  if (s256 && s256.trim()) return { signature: s256.trim(), algorithms: ['sha256'] };
  return undefined;
}

/** Compute the hex HMAC of a raw body. */
export function computeSignature(rawBody: RawBody, secret: string, algorithm: Algorithm = 'sha512'): string {
  return createHmac(algorithm, secret).update(toBuffer(rawBody)).digest('hex');
}

function safeEqualHex(expectedHex: string, receivedHex: string): boolean {
  const received = receivedHex.toLowerCase();
  if (!/^[0-9a-f]*$/.test(received) || received.length !== expectedHex.length) {
    // Still do a comparison of equal length to keep timing uniform.
    const a = Buffer.from(expectedHex);
    timingSafeEqual(a, a);
    return false;
  }
  return timingSafeEqual(Buffer.from(expectedHex, 'utf8'), Buffer.from(received, 'utf8'));
}

/**
 * Verify a HiTechPay webhook signature.
 *
 * @param rawBody   The exact raw request body (do NOT JSON.parse + re-stringify).
 * @param signatureHeader  Value of `x-webhook-signature-512` (HMAC-SHA512 hex) or
 *                  `x-webhook-signature-256` (HMAC-SHA256 hex), or the whole headers object.
 * @param paymentResponseHashKey  The profile's "Payment Response Hash Key" from the dashboard.
 * @returns true if the signature is valid.
 */
export function verify(rawBody: RawBody, signatureHeader: SignatureInput, paymentResponseHashKey: string): boolean {
  if (!paymentResponseHashKey) {
    throw new TypeError('HiTechPay webhooks: paymentResponseHashKey is required');
  }
  const resolved = resolveSignature(signatureHeader);
  if (!resolved) return false;
  const body = toBuffer(rawBody);
  let ok = false;
  for (const alg of resolved.algorithms) {
    // Evaluate all candidates (no short-circuit) to keep timing independent of which matched.
    const match = safeEqualHex(computeSignature(body, paymentResponseHashKey, alg), resolved.signature);
    ok = ok || match;
  }
  return ok;
}

/**
 * Verify the signature and parse the event. Throws
 * `HiTechPaySignatureVerificationError` if the signature is missing/invalid
 * or the body is not valid JSON.
 */
export function constructEvent<T = Record<string, unknown>>(
  rawBody: RawBody,
  signatureHeader: SignatureInput,
  paymentResponseHashKey: string,
): WebhookEvent<T> {
  if (resolveSignature(signatureHeader) === undefined) {
    throw new HiTechPaySignatureVerificationError({
      message: `Missing webhook signature (expected ${SIGNATURE_HEADER_512} or ${SIGNATURE_HEADER_256})`,
      status: 400,
      code: 'signature_missing',
    });
  }
  if (!verify(rawBody, signatureHeader, paymentResponseHashKey)) {
    throw new HiTechPaySignatureVerificationError({
      message: 'Webhook signature verification failed',
      status: 400,
      code: 'signature_invalid',
    });
  }
  const text = toBuffer(rawBody).toString('utf8');
  try {
    return JSON.parse(text) as WebhookEvent<T>;
  } catch (err) {
    throw new HiTechPaySignatureVerificationError({
      message: 'Webhook body is not valid JSON',
      status: 400,
      code: 'invalid_payload',
      cause: err,
    });
  }
}

export const webhooks = {
  verify,
  constructEvent,
  computeSignature,
  SIGNATURE_HEADER_512,
  SIGNATURE_HEADER_256,
} as const;
