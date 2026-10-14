import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  HiTechPay,
  HiTechPayAPIError,
  HiTechPayAuthenticationError,
  HiTechPayConnectionError,
  HiTechPayError,
  HiTechPayInvalidRequestError,
  HiTechPayNotFoundError,
  HiTechPayRateLimitError,
  vnPaymentMethods,
} from '../src';
import { mockFetch } from './helpers';

const fast = { retryBaseDelayMs: 1, retryMaxDelayMs: 2 };

test('constructor requires apiKey and defaults baseUrl', () => {
  assert.throws(() => new HiTechPay({ apiKey: '' }), TypeError);
  const c = new HiTechPay({ apiKey: 'k', fetch: mockFetch([{ status: 200 }]).fetch });
  assert.equal(c.baseUrl, 'https://api.hitechpay.vn');
  const s = new HiTechPay({ apiKey: 'k', baseUrl: HiTechPay.SANDBOX_BASE_URL + '/', fetch: mockFetch([{ status: 200 }]).fetch });
  assert.equal(s.baseUrl, 'https://sandbox-api.hitechpay.vn');
});

test('payments.create sends api-key header, JSON body and POST /payments', async () => {
  const { fetch, calls } = mockFetch([{ status: 200, body: { payment_id: 'pay_1', status: 'requires_customer_action', amount: 150000, currency: 'VND', next_action: { type: 'qr_code_information', qr_code_url: 'https://x/qr.png' } } }]);
  const c = new HiTechPay({ apiKey: 'snd_secret', baseUrl: 'https://sandbox-api.hitechpay.vn', fetch });
  const p = await c.payments.create({ amount: 150000, currency: 'VND', confirm: true, connector: ['sepay'], ...vnPaymentMethods.vietQr });
  assert.equal(p.payment_id, 'pay_1');
  assert.equal(p.next_action?.type, 'qr_code_information');
  assert.equal(calls.length, 1);
  const call = calls[0];
  assert.equal(call.method, 'POST');
  assert.equal(call.url, 'https://sandbox-api.hitechpay.vn/payments');
  assert.equal(call.headers['api-key'], 'snd_secret');
  assert.equal(call.headers['Content-Type'], 'application/json');
  assert.equal(call.headers['Accept'], 'application/json');
  assert.match(call.headers['User-Agent'], /^hitechpay-node\//);
  assert.deepEqual(JSON.parse(call.body!), {
    amount: 150000,
    currency: 'VND',
    confirm: true,
    connector: ['sepay'],
    payment_method: 'real_time_payment',
    payment_method_type: 'viet_qr',
    payment_method_data: { real_time_payment: { viet_qr: {} } },
  });
});

test('resource paths and methods match Hyperswitch v1', async () => {
  const { fetch, calls } = mockFetch([{ status: 200, body: {} }]);
  const c = new HiTechPay({ apiKey: 'k', baseUrl: 'https://h', fetch });
  await c.payments.retrieve('pay_1', { force_sync: true });
  await c.payments.update('pay_1', { description: 'x' });
  await c.payments.confirm('pay_1', { return_url: 'https://shop/return' });
  await c.payments.capture('pay_1', { amount_to_capture: 100 });
  await c.payments.cancel('pay_1', { cancellation_reason: 'requested_by_customer' });
  await c.payments.list({ limit: 10, customer_id: 'cus 1' });
  await c.refunds.create({ payment_id: 'pay_1', amount: 50 });
  await c.refunds.retrieve('ref_1');
  await c.refunds.update('ref_1', { reason: 'r' });
  await c.refunds.list({ payment_id: 'pay_1' });
  await c.customers.create({ name: 'Nguyễn Văn A' });
  await c.customers.retrieve('cus_1');
  await c.customers.update('cus_1', { email: 'a@b.vn' });
  await c.customers.delete('cus_1');
  await c.customers.list({ limit: 5 });
  await c.paymentMethods.list('cus_1');
  await c.paymentLinks.retrieve('plink_1');
  await c.mandates.retrieve('man_1');
  await c.mandates.revoke('man_1');
  await c.disputes.retrieve('dp_1');
  await c.disputes.list({ limit: 3 });
  await c.payments.retrieve('a/b?c');
  assert.deepEqual(
    calls.map((x) => `${x.method} ${x.url.replace('https://h', '')}`),
    [
      'GET /payments/pay_1?force_sync=true',
      'POST /payments/pay_1',
      'POST /payments/pay_1/confirm',
      'POST /payments/pay_1/capture',
      'POST /payments/pay_1/cancel',
      'GET /payments/list?limit=10&customer_id=cus%201',
      'POST /refunds',
      'GET /refunds/ref_1',
      'POST /refunds/ref_1',
      'POST /refunds/list',
      'POST /customers',
      'GET /customers/cus_1',
      'POST /customers/cus_1',
      'DELETE /customers/cus_1',
      'GET /customers/list?limit=5',
      'GET /customers/cus_1/payment_methods',
      'GET /payment_link/plink_1',
      'GET /mandates/man_1',
      'POST /mandates/revoke/man_1',
      'GET /disputes/dp_1',
      'GET /disputes/list?limit=3',
      'GET /payments/a%2Fb%3Fc',
    ],
  );
  // GET requests carry no body / content-type
  assert.equal(calls[0].body, undefined);
  assert.equal(calls[0].headers['Content-Type'], undefined);
  assert.deepEqual(JSON.parse(calls[3].body!), { amount_to_capture: 100 });
  await assert.rejects(() => c.payments.retrieve(''), TypeError);
});

test('retries GET on 429/5xx and network errors, then succeeds', async () => {
  const { fetch, calls } = mockFetch([
    { status: 503, body: { error: { type: 'api', message: 'down', code: 'HE_00' } } },
    new TypeError('fetch failed'),
    { status: 429, body: '', headers: { 'retry-after': '0' } },
    { status: 200, body: { payment_id: 'pay_1', status: 'succeeded', amount: 1, currency: 'VND' } },
  ]);
  const c = new HiTechPay({ apiKey: 'k', fetch, maxRetries: 3, ...fast });
  const p = await c.payments.retrieve('pay_1');
  assert.equal(p.status, 'succeeded');
  assert.equal(calls.length, 4);
});

test('gives up after maxRetries and throws the last error', async () => {
  const { fetch, calls } = mockFetch([{ status: 500, body: { error: { type: 'api', message: 'boom', code: 'HE_00' } } }]);
  const c = new HiTechPay({ apiKey: 'k', fetch, maxRetries: 2, ...fast });
  await assert.rejects(c.customers.retrieve('cus_1'), (e: unknown) => {
    assert.ok(e instanceof HiTechPayAPIError);
    assert.equal((e as HiTechPayError).status, 500);
    return true;
  });
  assert.equal(calls.length, 3);
});

test('does NOT retry non-idempotent POST (payments.create) on 5xx', async () => {
  const { fetch, calls } = mockFetch([{ status: 502, body: 'Bad Gateway' }]);
  const c = new HiTechPay({ apiKey: 'k', fetch, maxRetries: 3, ...fast });
  await assert.rejects(c.payments.create({ amount: 1, currency: 'VND' }), HiTechPayAPIError);
  assert.equal(calls.length, 1);
});

test('POST can opt in to retries with { idempotent: true }', async () => {
  const { fetch, calls } = mockFetch([{ status: 503 }, { status: 200, body: { payment_id: 'p' } }]);
  const c = new HiTechPay({ apiKey: 'k', fetch, maxRetries: 2, ...fast });
  await c.payments.create({ amount: 1, currency: 'VND', payment_id: 'order_42' }, { idempotent: true });
  assert.equal(calls.length, 2);
});

test('does not retry 4xx', async () => {
  const { fetch, calls } = mockFetch([{ status: 404, body: { error: { type: 'invalid_request', message: 'Payment does not exist in our records', code: 'HE_02' } } }]);
  const c = new HiTechPay({ apiKey: 'k', fetch, maxRetries: 3, ...fast });
  await assert.rejects(c.payments.retrieve('nope'), HiTechPayNotFoundError);
  assert.equal(calls.length, 1);
});

test('maps error bodies to typed errors with status, code, message, raw', async () => {
  const body = { error: { type: 'invalid_request', message: 'Missing required param: currency', code: 'IR_04' } };
  const { fetch } = mockFetch([{ status: 400, body, headers: { 'x-request-id': 'req_123' } }]);
  const c = new HiTechPay({ apiKey: 'k', fetch });
  try {
    await c.payments.create({ amount: 1 } as never);
    assert.fail('should throw');
  } catch (e) {
    assert.ok(e instanceof HiTechPayInvalidRequestError);
    assert.ok(e instanceof HiTechPayError);
    assert.ok(e instanceof Error);
    const err = e as HiTechPayError;
    assert.equal(err.name, 'HiTechPayInvalidRequestError');
    assert.equal(err.status, 400);
    assert.equal(err.code, 'IR_04');
    assert.equal(err.type, 'invalid_request');
    assert.equal(err.message, 'Missing required param: currency');
    assert.deepEqual(err.raw, body);
    assert.equal(err.requestId, 'req_123');
  }
});

test('maps 401, 429 and non-JSON bodies', async () => {
  const c1 = new HiTechPay({ apiKey: 'bad', fetch: mockFetch([{ status: 401, body: { error: { type: 'invalid_request', message: 'API key not provided or invalid API key used', code: 'IR_01' } } }]).fetch });
  await assert.rejects(c1.payments.create({ amount: 1, currency: 'VND' }), (e: unknown) => e instanceof HiTechPayAuthenticationError && (e as HiTechPayError).code === 'IR_01');

  const c2 = new HiTechPay({ apiKey: 'k', maxRetries: 0, fetch: mockFetch([{ status: 429, body: 'slow down' }]).fetch });
  await assert.rejects(c2.payments.list(), (e: unknown) => e instanceof HiTechPayRateLimitError && (e as HiTechPayError).message === 'slow down' && (e as HiTechPayError).raw === 'slow down');

  const c3 = new HiTechPay({ apiKey: 'k', maxRetries: 0, fetch: mockFetch([{ status: 418 }]).fetch });
  await assert.rejects(c3.payments.list(), (e: unknown) => e instanceof HiTechPayError && (e as HiTechPayError).status === 418 && /HTTP 418/.test((e as Error).message));
});

test('network failure surfaces as HiTechPayConnectionError (status 0)', async () => {
  const c = new HiTechPay({ apiKey: 'k', maxRetries: 1, ...fast, fetch: mockFetch([new TypeError('ECONNRESET')]).fetch });
  await assert.rejects(c.payments.retrieve('p'), (e: unknown) => e instanceof HiTechPayConnectionError && (e as HiTechPayError).status === 0);
});

test('timeout aborts the request', async () => {
  const hanging = ((_url: string, init: { signal?: AbortSignal }) =>
    new Promise((_resolve, reject) => {
      init.signal?.addEventListener('abort', () => reject(new Error('aborted')));
    })) as never;
  const c = new HiTechPay({ apiKey: 'k', timeoutMs: 20, maxRetries: 0, fetch: hanging });
  await assert.rejects(c.payments.retrieve('p'), (e: unknown) => e instanceof HiTechPayConnectionError && /timed out/.test((e as Error).message));
});

test('uses global fetch when none is passed', async () => {
  const original = globalThis.fetch;
  const { fetch, calls } = mockFetch([{ status: 200, body: { ok: true } }]);
  (globalThis as { fetch: unknown }).fetch = fetch;
  try {
    const c = new HiTechPay({ apiKey: 'k' });
    await c.request('GET', 'health');
    assert.equal(calls[0].url, 'https://api.hitechpay.vn/health');
  } finally {
    (globalThis as { fetch: unknown }).fetch = original;
  }
});
