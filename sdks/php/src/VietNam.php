<?php

declare(strict_types=1);

namespace HiTechPay;

/**
 * Vietnam helpers: VND formatting, HiTechPay VN payment-method presets and a
 * NAPAS VietQR (EMVCo MPM) payload builder.
 */
final class VietNam
{
    public const MOMO = 'momo';
    public const MOMO_ATM = 'momo_atm';
    public const VIET_QR = 'viet_qr';
    public const LOCAL_BANK_REDIRECT = 'local_bank_redirect';

    /** [payment_method, payment_method_type, payment_method_data path] */
    private const PRESETS = [
        self::MOMO => ['wallet', 'momo', ['wallet', 'momo_redirect']],
        self::MOMO_ATM => ['card_redirect', 'momo_atm', ['card_redirect', 'momo_atm']],
        self::VIET_QR => ['real_time_payment', 'viet_qr', ['real_time_payment', 'viet_qr']],
        self::LOCAL_BANK_REDIRECT => ['bank_redirect', 'local_bank_redirect', ['bank_redirect', 'local_bank_redirect']],
    ];

    /**
     * Format VND, e.g. 150000 => "150.000 ₫". VND has no minor unit.
     */
    public static function formatVnd(int|float|string $amount): string
    {
        if (!is_numeric($amount)) {
            throw new \InvalidArgumentException('formatVnd: invalid amount');
        }
        $n = is_int($amount) ? $amount : (int) round((float) $amount, 0, PHP_ROUND_HALF_UP);
        $neg = $n < 0;
        $digits = ltrim((string) $n, '-');
        $grouped = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $digits);
        return ($neg ? '-' : '') . $grouped . ' ₫';
    }

    /**
     * Payment-method preset to merge into payments->create().
     * Empty objects are encoded as JSON {} (not []).
     *
     * @return array{payment_method:string, payment_method_type:string, payment_method_data:array<string,mixed>}
     */
    public static function paymentMethod(string $name): array
    {
        if (!isset(self::PRESETS[$name])) {
            throw new \InvalidArgumentException("Unknown VN payment method: {$name}");
        }
        [$pm, $pmt, [$outer, $inner]] = self::PRESETS[$name];
        return [
            'payment_method' => $pm,
            'payment_method_type' => $pmt,
            'payment_method_data' => [$outer => [$inner => new \stdClass()]],
        ];
    }

    /** MoMo e-wallet redirect (connector `momo`). */
    public static function momo(): array
    {
        return self::paymentMethod(self::MOMO);
    }

    /** MoMo ATM card redirect (connector `momo`). */
    public static function momoAtm(): array
    {
        return self::paymentMethod(self::MOMO_ATM);
    }

    /** VietQR (connectors `sepay`, `payos`, `pay2s`, ...). */
    public static function vietQr(): array
    {
        return self::paymentMethod(self::VIET_QR);
    }

    /** Domestic internet-banking redirect. */
    public static function localBankRedirect(): array
    {
        return self::paymentMethod(self::LOCAL_BANK_REDIRECT);
    }

    /** CRC16-CCITT-FALSE (poly 0x1021, init 0xFFFF, no reflection, xorout 0). */
    public static function crc16(string $data): int
    {
        $crc = 0xFFFF;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($b = 0; $b < 8; $b++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }
        return $crc;
    }

    /** Strip Vietnamese diacritics and characters banking apps reject. */
    public static function sanitizeMemo(string $memo): string
    {
        $map = [
            'à' => 'a', 'á' => 'a', 'ạ' => 'a', 'ả' => 'a', 'ã' => 'a', 'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ậ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a',
            'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ặ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a',
            'è' => 'e', 'é' => 'e', 'ẹ' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ệ' => 'e', 'ể' => 'e', 'ễ' => 'e',
            'ì' => 'i', 'í' => 'i', 'ị' => 'i', 'ỉ' => 'i', 'ĩ' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ọ' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ộ' => 'o', 'ổ' => 'o', 'ỗ' => 'o',
            'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ợ' => 'o', 'ở' => 'o', 'ỡ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'ụ' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ự' => 'u', 'ử' => 'u', 'ữ' => 'u',
            'ỳ' => 'y', 'ý' => 'y', 'ỵ' => 'y', 'ỷ' => 'y', 'ỹ' => 'y',
            'đ' => 'd', 'Đ' => 'D',
        ];
        $upper = [];
        if (function_exists('mb_strtoupper')) {
            foreach ($map as $k => $v) {
                $upper[mb_strtoupper($k, 'UTF-8')] = strtoupper($v);
            }
        }
        $s = strtr($memo, $map + $upper);
        if (class_exists(\Normalizer::class)) {
            // Handle decomposed (NFD) input as well.
            $s = (string) \Normalizer::normalize($s, \Normalizer::FORM_D);
            $s = (string) preg_replace('/\p{Mn}+/u', '', $s);
        }
        $s = (string) preg_replace('/[^A-Za-z0-9 .,\-_\/]/', '', $s);
        $s = (string) preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    private static function tlv(string $tag, string $value): string
    {
        $len = strlen($value);
        if ($len > 99) {
            throw new \LengthException("VietQR: value of tag {$tag} is too long ({$len} > 99)");
        }
        return $tag . str_pad((string) $len, 2, '0', STR_PAD_LEFT) . $value;
    }

    /**
     * Build a NAPAS VietQR (EMVCo merchant-presented) payload string.
     *
     * 00=01, 01=12 (dynamic, with amount) | 11 (static),
     * 38={00=A000000727, 01={00=BIN, 01=account}, 02=QRIBFTTA}, 53=704,
     * 54=amount, 58=VN, 62={08=memo}, 63=CRC16 (upper-case hex).
     *
     * @param string   $bankBin       6-digit NAPAS BIN, e.g. "970422" (MB)
     * @param string   $accountNumber beneficiary account number
     * @param int|null $amount        VND amount; null for a static QR
     * @param string|null $memo       transfer content (diacritics stripped, max 25 chars)
     */
    public static function buildVietQrPayload(
        string $bankBin,
        string $accountNumber,
        ?int $amount = null,
        ?string $memo = null,
        bool $toCard = false,
    ): string {
        if (!preg_match('/^\d{6}$/', $bankBin)) {
            throw new \InvalidArgumentException('VietQR: bankBin must be a 6-digit NAPAS BIN (e.g. "970422")');
        }
        if (!preg_match('/^[0-9A-Za-z]{1,19}$/', $accountNumber)) {
            throw new \InvalidArgumentException('VietQR: accountNumber must be 1-19 alphanumeric characters');
        }
        if ($amount !== null && ($amount <= 0 || strlen((string) $amount) > 13)) {
            throw new \InvalidArgumentException('VietQR: amount must be a positive integer number of VND (max 13 digits)');
        }

        $beneficiary = self::tlv('00', $bankBin) . self::tlv('01', $accountNumber);
        $merchantInfo = self::tlv('00', 'A000000727') . self::tlv('01', $beneficiary) . self::tlv('02', $toCard ? 'QRIBFTTC' : 'QRIBFTTA');

        $payload = self::tlv('00', '01')
            . self::tlv('01', $amount !== null ? '12' : '11')
            . self::tlv('38', $merchantInfo)
            . self::tlv('53', '704');
        if ($amount !== null) {
            $payload .= self::tlv('54', (string) $amount);
        }
        $payload .= self::tlv('58', 'VN');
        if ($memo !== null && $memo !== '') {
            $clean = self::sanitizeMemo($memo);
            if (strlen($clean) > 25) {
                throw new \LengthException('VietQR: memo too long after sanitising (' . strlen($clean) . ' > 25)');
            }
            if ($clean !== '') {
                $payload .= self::tlv('62', self::tlv('08', $clean));
            }
        }
        $payload .= '6304';
        return $payload . sprintf('%04X', self::crc16($payload));
    }

    /**
     * Parse a top-level EMVCo TLV string into [tag => value].
     *
     * @return array<string,string>
     */
    public static function parseTlv(string $payload): array
    {
        $out = [];
        $i = 0;
        $n = strlen($payload);
        while ($i < $n) {
            $tag = substr($payload, $i, 2);
            $lenStr = substr($payload, $i + 2, 2);
            if (!ctype_digit($tag) || !ctype_digit($lenStr)) {
                throw new \UnexpectedValueException("Invalid TLV at offset {$i}");
            }
            $len = (int) $lenStr;
            $out[$tag] = substr($payload, $i + 4, $len);
            $i += 4 + $len;
        }
        return $out;
    }
}
