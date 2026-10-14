import { test } from 'node:test';
import assert from 'node:assert/strict';
import { HiTechPay, buildVietQrPayload, crc16CcittFalse, formatVnd, parseTlv, sanitizeMemo, vn, vnPaymentMethod, vnPaymentMethods } from '../src';

test('CRC16-CCITT-FALSE check value for "123456789" is 0x29B1', () => {
  assert.equal(crc16CcittFalse('123456789'), 0x29b1);
  assert.equal(crc16CcittFalse(''), 0xffff);
});

test('formatVnd', () => {
  assert.equal(formatVnd(150000), '150.000 ₫');
  assert.equal(formatVnd(0), '0 ₫');
  assert.equal(formatVnd(999), '999 ₫');
  assert.equal(formatVnd(1000), '1.000 ₫');
  assert.equal(formatVnd(1234567890), '1.234.567.890 ₫');
  assert.equal(formatVnd(-25000), '-25.000 ₫');
  assert.equal(formatVnd(1499.5), '1.500 ₫');
  assert.equal(formatVnd('50000'), '50.000 ₫');
  assert.equal(formatVnd(10n ** 15n), '1.000.000.000.000.000 ₫');
  assert.equal(HiTechPay.vn.formatVnd(150000), '150.000 ₫');
  assert.throws(() => formatVnd(NaN), TypeError);
});

test('vnPaymentMethods presets', () => {
  assert.deepEqual(vnPaymentMethods.momo, { payment_method: 'wallet', payment_method_type: 'momo', payment_method_data: { wallet: { momo_redirect: {} } } });
  assert.deepEqual(vnPaymentMethods.momoAtm, { payment_method: 'card_redirect', payment_method_type: 'momo_atm', payment_method_data: { card_redirect: { momo_atm: {} } } });
  assert.deepEqual(vnPaymentMethods.vietQr, { payment_method: 'real_time_payment', payment_method_type: 'viet_qr', payment_method_data: { real_time_payment: { viet_qr: {} } } });
  assert.deepEqual(vnPaymentMethods.localBankRedirect, { payment_method: 'bank_redirect', payment_method_type: 'local_bank_redirect', payment_method_data: { bank_redirect: { local_bank_redirect: {} } } });
  assert.ok(Object.isFrozen(vnPaymentMethods.momo));
  const copy = vnPaymentMethod('vietQr');
  assert.deepEqual(copy, vnPaymentMethods.vietQr);
  assert.notEqual(copy.payment_method_data, vnPaymentMethods.vietQr.payment_method_data);
  assert.equal(vn.vnPaymentMethods, vnPaymentMethods);
});

test('buildVietQrPayload: dynamic QR with amount and memo', () => {
  const payload = buildVietQrPayload({ bankBin: '970422', accountNumber: '0123456789', amount: 150000, memo: 'Thanh toán ĐH 1001' });
  const expectedBody =
    '000201' +
    '010212' +
    '3854' + '0010A000000727' + '0124' + '0006970422' + '01100123456789' + '0208QRIBFTTA' +
    '5303704' +
    '5406150000' +
    '5802VN' +
    '6222' + '0818Thanh toan DH 1001' +
    '6304';
  assert.equal(payload.slice(0, -4), expectedBody);
  const crc = crc16CcittFalse(expectedBody).toString(16).toUpperCase().padStart(4, '0');
  assert.equal(payload.slice(-4), crc);
  assert.match(payload.slice(-4), /^[0-9A-F]{4}$/);

  const tags = parseTlv(payload);
  assert.equal(tags['00'], '01');
  assert.equal(tags['01'], '12');
  assert.equal(tags['53'], '704');
  assert.equal(tags['54'], '150000');
  assert.equal(tags['58'], 'VN');
  assert.equal(tags['63'], crc);
  const m = parseTlv(tags['38']);
  assert.equal(m['00'], 'A000000727');
  assert.equal(m['02'], 'QRIBFTTA');
  assert.deepEqual(parseTlv(m['01']), { '00': '970422', '01': '0123456789' });
  assert.deepEqual(parseTlv(tags['62']), { '08': 'Thanh toan DH 1001' });
});

test('buildVietQrPayload: static QR (no amount, no memo)', () => {
  const payload = buildVietQrPayload({ bankBin: '970436', accountNumber: '1234567' });
  const tags = parseTlv(payload);
  assert.equal(tags['01'], '11');
  assert.equal(tags['54'], undefined);
  assert.equal(tags['62'], undefined);
  // CRC is self-consistent: CRC over everything up to and including "6304"
  assert.equal(parseInt(tags['63'], 16), crc16CcittFalse(payload.slice(0, -4)));
});

test('buildVietQrPayload validation', () => {
  assert.throws(() => buildVietQrPayload({ bankBin: '97042', accountNumber: '1' }), TypeError);
  assert.throws(() => buildVietQrPayload({ bankBin: '970422', accountNumber: '' }), TypeError);
  assert.throws(() => buildVietQrPayload({ bankBin: '970422', accountNumber: '12-3' }), TypeError);
  assert.throws(() => buildVietQrPayload({ bankBin: '970422', accountNumber: '1', amount: 0 }), TypeError);
  assert.throws(() => buildVietQrPayload({ bankBin: '970422', accountNumber: '1', amount: 10.5 }), TypeError);
  assert.throws(() => buildVietQrPayload({ bankBin: '970422', accountNumber: '1', memo: 'x'.repeat(26) }), RangeError);
});

test('sanitizeMemo strips Vietnamese diacritics', () => {
  assert.equal(sanitizeMemo('  Đơn hàng   số #42 – Hà Nội '), 'Don hang so 42 Ha Noi');
});
