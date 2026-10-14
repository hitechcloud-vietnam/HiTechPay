/**
 * Vietnam-specific helpers: VND formatting, HiTechPay VN payment method
 * presets and a NAPAS VietQR (EMVCo MPM) payload builder.
 */

/**
 * Format an amount of VND as e.g. `150.000 ₫`.
 * VND is a zero-decimal currency; non-integers are rounded half away from zero.
 */
export function formatVnd(amount: number | bigint | string): string {
  let n: bigint;
  if (typeof amount === 'bigint') {
    n = amount;
  } else {
    const num = typeof amount === 'string' ? Number(amount) : amount;
    if (!Number.isFinite(num)) throw new TypeError(`formatVnd: invalid amount ${String(amount)}`);
    n = BigInt(Math.sign(num) * Math.round(Math.abs(num)));
  }
  const negative = n < 0n;
  const digits = (negative ? -n : n).toString();
  const grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  return `${negative ? '-' : ''}${grouped} ₫`;
}

/** Payment method presets for HiTechPay's Vietnamese connectors. */
export const vnPaymentMethods = Object.freeze({
  /** MoMo e-wallet (redirect / app-to-app). Connector: `momo`. */
  momo: Object.freeze({
    payment_method: 'wallet',
    payment_method_type: 'momo',
    payment_method_data: { wallet: { momo_redirect: {} } },
  }),
  /** MoMo ATM — domestic ATM card via MoMo gateway. Connector: `momo`. */
  momoAtm: Object.freeze({
    payment_method: 'card_redirect',
    payment_method_type: 'momo_atm',
    payment_method_data: { card_redirect: { momo_atm: {} } },
  }),
  /** VietQR (NAPAS 247 bank transfer QR). Connectors: `sepay`, `payos`, `pay2s`, ... */
  vietQr: Object.freeze({
    payment_method: 'real_time_payment',
    payment_method_type: 'viet_qr',
    payment_method_data: { real_time_payment: { viet_qr: {} } },
  }),
  /** Domestic internet-banking redirect. */
  localBankRedirect: Object.freeze({
    payment_method: 'bank_redirect',
    payment_method_type: 'local_bank_redirect',
    payment_method_data: { bank_redirect: { local_bank_redirect: {} } },
  }),
} as const);

export type VnPaymentMethodName = keyof typeof vnPaymentMethods;

/**
 * Return a fresh (mutable, deep-copied) preset that can be spread into
 * `payments.create(...)`.
 */
export function vnPaymentMethod(name: VnPaymentMethodName): {
  payment_method: string;
  payment_method_type: string;
  payment_method_data: Record<string, Record<string, Record<string, never>>>;
} {
  const preset = vnPaymentMethods[name];
  if (!preset) throw new TypeError(`Unknown VN payment method: ${String(name)}`);
  return JSON.parse(JSON.stringify(preset));
}

/** CRC16-CCITT-FALSE (poly 0x1021, init 0xFFFF, no reflection, xorout 0). */
export function crc16CcittFalse(input: string | Uint8Array): number {
  const bytes = typeof input === 'string' ? new TextEncoder().encode(input) : input;
  let crc = 0xffff;
  for (const byte of bytes) {
    crc ^= byte << 8;
    for (let i = 0; i < 8; i++) {
      crc = crc & 0x8000 ? ((crc << 1) ^ 0x1021) & 0xffff : (crc << 1) & 0xffff;
    }
  }
  return crc;
}

function tlv(tag: string, value: string): string {
  const len = new TextEncoder().encode(value).length;
  if (len > 99) throw new RangeError(`VietQR: value of tag ${tag} is too long (${len} > 99)`);
  return `${tag}${String(len).padStart(2, '0')}${value}`;
}

/** Remove Vietnamese diacritics and characters that banking apps reject. */
export function sanitizeMemo(memo: string): string {
  return memo
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/đ/g, 'd')
    .replace(/Đ/g, 'D')
    .replace(/[^A-Za-z0-9 .,\-_/]/g, '')
    .replace(/\s+/g, ' ')
    .trim();
}

export interface VietQrOptions {
  /** 6-digit NAPAS bank BIN, e.g. `970422` (MB), `970436` (Vietcombank). */
  bankBin: string;
  /** Beneficiary account number (or card number). */
  accountNumber: string;
  /** Amount in VND (integer). Omit for a static QR. */
  amount?: number;
  /** Transfer content / order reference. Diacritics are stripped. Max 25 chars after sanitising. */
  memo?: string;
  /** Use `QRIBFTTC` (to card) instead of `QRIBFTTA` (to account). Default false. */
  toCard?: boolean;
}

/**
 * Build a NAPAS VietQR (EMVCo merchant-presented) payload string that any
 * Vietnamese banking app can scan.
 *
 * Layout: 00=01, 01=12 (dynamic, has amount) | 11 (static),
 * 38={00=A000000727, 01={00=BIN, 01=account}, 02=QRIBFTTA}, 53=704,
 * 54=amount, 58=VN, 62={08=memo}, 63=CRC16 (upper-case hex).
 */
export function buildVietQrPayload(opts: VietQrOptions): string {
  const { bankBin, accountNumber, amount, memo, toCard = false } = opts;
  if (!/^\d{6}$/.test(bankBin ?? '')) {
    throw new TypeError('VietQR: bankBin must be a 6-digit NAPAS BIN (e.g. "970422")');
  }
  if (!/^[0-9A-Za-z]{1,19}$/.test(accountNumber ?? '')) {
    throw new TypeError('VietQR: accountNumber must be 1-19 alphanumeric characters');
  }
  const hasAmount = amount !== undefined && amount !== null;
  if (hasAmount && (!Number.isInteger(amount) || (amount as number) <= 0 || String(amount).length > 13)) {
    throw new TypeError('VietQR: amount must be a positive integer number of VND (max 13 digits)');
  }

  const beneficiary = tlv('00', bankBin) + tlv('01', accountNumber);
  const merchantInfo =
    tlv('00', 'A000000727') + tlv('01', beneficiary) + tlv('02', toCard ? 'QRIBFTTC' : 'QRIBFTTA');

  let payload = tlv('00', '01') + tlv('01', hasAmount ? '12' : '11') + tlv('38', merchantInfo) + tlv('53', '704');
  if (hasAmount) payload += tlv('54', String(amount));
  payload += tlv('58', 'VN');
  if (memo) {
    const clean = sanitizeMemo(memo);
    if (clean.length > 25) {
      throw new RangeError(`VietQR: memo too long after sanitising (${clean.length} > 25): "${clean}"`);
    }
    if (clean) payload += tlv('62', tlv('08', clean));
  }
  payload += '6304';
  const crc = crc16CcittFalse(payload).toString(16).toUpperCase().padStart(4, '0');
  return payload + crc;
}

/** Parse a top-level EMVCo TLV string into a tag->value map (useful for debugging). */
export function parseTlv(payload: string): Record<string, string> {
  const out: Record<string, string> = {};
  let i = 0;
  while (i < payload.length) {
    const tag = payload.slice(i, i + 2);
    const len = Number(payload.slice(i + 2, i + 4));
    if (!/^\d{2}$/.test(tag) || !Number.isInteger(len)) throw new SyntaxError(`Invalid TLV at offset ${i}`);
    out[tag] = payload.slice(i + 4, i + 4 + len);
    i += 4 + len;
  }
  return out;
}

export const vn = {
  formatVnd,
  vnPaymentMethods,
  vnPaymentMethod,
  buildVietQrPayload,
  crc16CcittFalse,
  sanitizeMemo,
  parseTlv,
} as const;
