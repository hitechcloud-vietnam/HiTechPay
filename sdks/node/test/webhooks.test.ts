import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { HiTechPay, HiTechPaySignatureVerificationError, constructEvent, verifyWebhook } from '../src';

const key = 'pr_hash_key_test_123';
const body = JSON.stringify({
  merchant_id: 'merchant_1',
  event_id: 'evt_1',
  event_type: 'payment_succeeded',
  content: { type: 'payment_details', object: { payment_id: 'pay_1', status: 'succeeded', amount: 150000, currency: 'VND' } },
  timestamp: '2026-10-14T08:00:00.000Z',
});
const sig512 = createHmac('sha512', key).update(body).digest('hex');
const sig256 = createHmac('sha256', key).update(body).digest('hex');

test('verify accepts a valid HMAC-SHA512 signature (string or Buffer body)', () => {
  assert.equal(HiTechPay.webhooks.verify(body, sig512, key), true);
  assert.equal(HiTechPay.webhooks.verify(Buffer.from(body), sig512, key), true);
  assert.equal(HiTechPay.webhooks.verify(body, sig512.toUpperCase(), key), true);
  assert.equal(verifyWebhook(new TextEncoder().encode(body), sig512, key), true);
});

test('verify accepts a valid HMAC-SHA256 signature', () => {
  assert.equal(HiTechPay.webhooks.verify(body, sig256, key), true);
});

test('verify reads signature from a headers object (Node / Fetch style)', () => {
  assert.equal(HiTechPay.webhooks.verify(body, { 'X-Webhook-Signature-512': sig512 }, key), true);
  assert.equal(HiTechPay.webhooks.verify(body, { 'x-webhook-signature-256': sig256 }, key), true);
  const h = new Map([['x-webhook-signature-512', sig512]]);
  assert.equal(HiTechPay.webhooks.verify(body, { get: (n: string) => h.get(n) ?? null }, key), true);
  assert.equal(HiTechPay.webhooks.verify(body, {}, key), false);
});

test('verify rejects tampered body, wrong key, malformed or missing signature', () => {
  assert.equal(HiTechPay.webhooks.verify(body.replace('150000', '1'), sig512, key), false);
  assert.equal(HiTechPay.webhooks.verify(body, sig512, 'other-key'), false);
  assert.equal(HiTechPay.webhooks.verify(body, sig512.slice(0, -2) + 'zz', key), false);
  assert.equal(HiTechPay.webhooks.verify(body, 'abc', key), false);
  assert.equal(HiTechPay.webhooks.verify(body, '', key), false);
  assert.equal(HiTechPay.webhooks.verify(body, undefined, key), false);
  // signature-256 header carrying a sha512 value must not pass
  assert.equal(HiTechPay.webhooks.verify(body, { 'x-webhook-signature-256': sig512 }, key), false);
  assert.throws(() => HiTechPay.webhooks.verify(body, sig512, ''), TypeError);
});

test('constructEvent verifies and parses', () => {
  const evt = constructEvent<{ payment_id: string }>(body, sig512, key);
  assert.equal(evt.event_type, 'payment_succeeded');
  assert.equal(evt.content.object.payment_id, 'pay_1');
  assert.throws(() => constructEvent(body, 'deadbeef', key), HiTechPaySignatureVerificationError);
  assert.throws(() => constructEvent(body, undefined, key), (e: unknown) => e instanceof HiTechPaySignatureVerificationError && (e as HiTechPaySignatureVerificationError).code === 'signature_missing');
  const notJson = 'not json';
  assert.throws(
    () => constructEvent(notJson, createHmac('sha512', key).update(notJson).digest('hex'), key),
    (e: unknown) => e instanceof HiTechPaySignatureVerificationError && (e as HiTechPaySignatureVerificationError).code === 'invalid_payload',
  );
});

test('computeSignature matches node:crypto', () => {
  assert.equal(HiTechPay.webhooks.computeSignature(body, key), sig512);
  assert.equal(HiTechPay.webhooks.computeSignature(body, key, 'sha256'), sig256);
});
