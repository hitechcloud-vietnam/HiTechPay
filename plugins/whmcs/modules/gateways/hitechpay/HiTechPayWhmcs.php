<?php

/**
 * HiTechPay for WHMCS — shared logic used by modules/gateways/hitechpay.php and
 * modules/gateways/callback/hitechpay.php.
 *
 * Kept free of WHMCS bootstrapping so it can be unit tested with mocked WHMCS
 * functions (see plugins/whmcs/tests/run.php).
 *
 * Copyright (c) 2026 Pho Tue Software Solutions JSC & HiTechCloud Vietnam — Apache-2.0
 */

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use HiTechPay\Exception\HiTechPayException;
use HiTechPay\HiTechPayClient;
use HiTechPay\Http\Transport;
use HiTechPay\VietNam;

// Autoloader for the bundled copy of the HiTechPay PHP SDK (sdks/php/src -> lib/).
spl_autoload_register(static function (string $class): void {
    $prefix = 'HiTechPay\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = __DIR__ . '/lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

final class HiTechPayWhmcs
{
    public const MODULE = 'hitechpay';
    public const SIGNATURE_HEADER = 'X-Webhook-Signature-512';

    /** Method keys used in the invoice page buttons (?hitechpay_method=...). */
    public const METHOD_VIETQR = 'vietqr';
    public const METHOD_MOMO = 'momo';
    public const METHOD_REDIRECT = 'bankpage';
    public const METHOD_CARD = 'card';

    /** Test seam: inject an HTTP transport (the SDK's Transport interface). */
    public static ?Transport $transport = null;

    /** Pending-payment reuse window for VietQR (seconds). */
    public const REUSE_SECONDS = 900;

    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'IRR', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
    private const THREE_DECIMAL = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    private const TEXT = [
        'vi' => [
            'pay_with' => 'Thanh toán bằng',
            'vietqr' => 'VietQR (chuyển khoản ngân hàng)',
            'momo' => 'Ví MoMo',
            'bankpage' => 'ZaloPay / VNPAY',
            'card' => 'Thẻ ATM / Visa / Mastercard / JCB',
            'scan' => 'Quét mã VietQR bằng ứng dụng ngân hàng để thanh toán',
            'amount' => 'Số tiền',
            'expires' => 'Mã hết hạn lúc',
            'paid' => 'Tôi đã thanh toán — kiểm tra trạng thái',
            'redirecting' => 'Đang chuyển tới trang thanh toán…',
            'continue' => 'Tiếp tục thanh toán',
            'pending' => 'Thanh toán đang được xử lý. Hoá đơn sẽ tự cập nhật khi HiTechPay xác nhận.',
            'error' => 'Không tạo được giao dịch HiTechPay. Vui lòng thử lại hoặc chọn phương thức khác.',
            'other' => 'Chọn phương thức khác',
        ],
        'en' => [
            'pay_with' => 'Pay with',
            'vietqr' => 'VietQR (bank transfer)',
            'momo' => 'MoMo wallet',
            'bankpage' => 'ZaloPay / VNPAY',
            'card' => 'ATM / Visa / Mastercard / JCB card',
            'scan' => 'Scan the VietQR code with your banking app to pay',
            'amount' => 'Amount',
            'expires' => 'Code expires at',
            'paid' => 'I have paid — check status',
            'redirecting' => 'Redirecting to the payment page…',
            'continue' => 'Continue to payment',
            'pending' => 'Your payment is being processed. The invoice updates automatically once HiTechPay confirms it.',
            'error' => 'Could not create the HiTechPay payment. Please try again or choose another method.',
            'other' => 'Choose another method',
        ],
    ];

    // ------------------------------------------------------------------ config / client

    /** WHMCS gateway configuration fields. */
    public static function configFields(): array
    {
        return [
            'FriendlyName' => ['Type' => 'System', 'Value' => 'HiTechPay'],
            'apiKey' => [
                'FriendlyName' => 'API Key (secret)',
                'Type' => 'password',
                'Size' => '64',
                'Description' => 'HiTechPay Dashboard → Developers → API Keys',
            ],
            'publishableKey' => [
                'FriendlyName' => 'Publishable Key',
                'Type' => 'text',
                'Size' => '64',
                'Description' => 'pk_snd_… / pk_prd_… (HiTechPay Dashboard → Developers → API Keys)',
            ],
            'webhookHashKey' => [
                'FriendlyName' => 'Payment Response Hash Key',
                'Type' => 'password',
                'Size' => '64',
                'Description' => 'Khoá ký webhook (HMAC-SHA512). Webhook URL: <code>https://&lt;whmcs&gt;/modules/gateways/callback/hitechpay.php</code>',
            ],
            'profileId' => [
                'FriendlyName' => 'Business Profile ID',
                'Type' => 'text',
                'Size' => '40',
                'Description' => 'Không bắt buộc / optional',
            ],
            'sandbox' => [
                'FriendlyName' => 'Sandbox',
                'Type' => 'yesno',
                'Description' => 'Dùng https://sandbox-api.hitechpay.vn (bỏ chọn = https://api.hitechpay.vn)',
            ],
            'enableVietQr' => [
                'FriendlyName' => 'VietQR',
                'Type' => 'yesno',
                'Description' => 'Chuyển khoản VietQR (hiển thị mã QR ngay trên hoá đơn)',
            ],
            'vietQrConnector' => [
                'FriendlyName' => 'VietQR connector',
                'Type' => 'dropdown',
                'Options' => ['' => 'Theo routing HiTechPay', 'sepay' => 'SePay', 'payos' => 'payOS', 'pay2s' => 'Pay2S', 'vnpay' => 'VNPAY-QR', 'zalopay' => 'ZaloPay'],
            ],
            'enableMomo' => [
                'FriendlyName' => 'MoMo',
                'Type' => 'yesno',
                'Description' => 'Ví MoMo (chuyển hướng / redirect)',
            ],
            'enableBankPage' => [
                'FriendlyName' => 'ZaloPay / VNPAY',
                'Type' => 'yesno',
                'Description' => 'Trang thanh toán ZaloPay / VNPAY / Viettel Money (ngân hàng nội địa)',
            ],
            'bankPageConnector' => [
                'FriendlyName' => 'ZaloPay / VNPAY connector',
                'Type' => 'dropdown',
                'Options' => ['' => 'Theo routing HiTechPay', 'vnpay' => 'VNPAY', 'zalopay' => 'ZaloPay', 'viettelmoney' => 'Viettel Money'],
            ],
            'enableCards' => [
                'FriendlyName' => 'Thẻ / Cards',
                'Type' => 'yesno',
                'Description' => 'Thẻ ATM / quốc tế qua trang thanh toán HiTechPay (payment link) — WHMCS không lưu số thẻ',
            ],
            'apiBaseUrl' => [
                'FriendlyName' => 'Custom API URL',
                'Type' => 'text',
                'Size' => '60',
                'Description' => 'Không bắt buộc — chỉ dùng cho HiTechPay tự triển khai (self-hosted)',
            ],
        ];
    }

    public static function baseUrl(array $params): string
    {
        $custom = trim((string) ($params['apiBaseUrl'] ?? ''));
        if ($custom !== '') {
            return rtrim($custom, '/');
        }
        return self::isOn($params['sandbox'] ?? '') ? HiTechPayClient::SANDBOX_BASE_URL : HiTechPayClient::DEFAULT_BASE_URL;
    }

    public static function client(array $params): HiTechPayClient
    {
        $config = [
            'api_key' => (string) ($params['apiKey'] ?? ''),
            'base_url' => self::baseUrl($params),
            'headers' => ['User-Agent' => 'hitechpay-whmcs/1.0 php/' . PHP_VERSION],
        ];
        if (self::$transport !== null) {
            $config['transport'] = self::$transport;
            $config['retry_base_delay_ms'] = 1;
            $config['retry_max_delay_ms'] = 2;
        }
        return new HiTechPayClient($config);
    }

    /** Enabled method keys, in display order. VietQR is used when nothing is ticked. */
    public static function enabledMethods(array $params): array
    {
        $map = [
            self::METHOD_VIETQR => 'enableVietQr',
            self::METHOD_MOMO => 'enableMomo',
            self::METHOD_REDIRECT => 'enableBankPage',
            self::METHOD_CARD => 'enableCards',
        ];
        $out = [];
        foreach ($map as $method => $key) {
            if (self::isOn($params[$key] ?? '')) {
                $out[] = $method;
            }
        }
        return $out ?: [self::METHOD_VIETQR];
    }

    public static function isOn(mixed $v): bool
    {
        return in_array(strtolower(trim((string) $v)), ['on', 'yes', '1', 'true'], true);
    }

    // ------------------------------------------------------------------ amounts / status / signature

    public static function multiplier(string $currency): int
    {
        $currency = strtoupper($currency);
        if (in_array($currency, self::ZERO_DECIMAL, true)) {
            return 1;
        }
        return in_array($currency, self::THREE_DECIMAL, true) ? 1000 : 100;
    }

    /** WHMCS decimal amount ("150000.00") -> API minor units (150000 for VND). */
    public static function toMinor(string|int|float $amount, string $currency): int
    {
        return (int) round((float) $amount * self::multiplier($currency));
    }

    /** API minor units -> WHMCS decimal amount. */
    public static function fromMinor(int|float|string $amount, string $currency): float
    {
        return round((float) $amount / self::multiplier($currency), 3);
    }

    /**
     * Map a HiTechPay (Hyperswitch) payment status to the module's outcome:
     * "success" (record payment), "failed", "pending", or "unknown".
     */
    public static function mapStatus(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'succeeded', 'partially_captured' => 'success',
            'failed', 'cancelled', 'expired' => 'failed',
            'processing', 'requires_customer_action', 'requires_payment_method', 'requires_confirmation',
            'requires_capture', 'requires_merchant_action', 'partially_captured_and_capturable' => 'pending',
            default => 'unknown',
        };
    }

    /** Map a HiTechPay refund status to a WHMCS refund result status. */
    public static function mapRefundStatus(?string $status): string
    {
        return match (strtolower((string) $status)) {
            // "pending": accepted by the gateway, settles asynchronously (Vietnamese wallets/banks).
            'succeeded', 'pending' => 'success',
            'failed', 'review', 'transaction_failure' => 'declined',
            default => 'error',
        };
    }

    /**
     * Verify the X-Webhook-Signature-512 header: lower-case hex HMAC-SHA512 of the raw body,
     * keyed with the profile's Payment Response Hash Key. Constant-time comparison.
     */
    public static function verifySignature(string $rawBody, ?string $signature, string $hashKey): bool
    {
        if ($hashKey === '' || $signature === null) {
            return false;
        }
        $signature = strtolower(trim($signature));
        if (!preg_match('/^[0-9a-f]{128}$/', $signature)) {
            return false;
        }
        return hash_equals(hash_hmac('sha512', $rawBody, $hashKey), $signature);
    }

    /** Read X-Webhook-Signature-512 from $_SERVER-style or header-array input. */
    public static function signatureFromServer(array $server): ?string
    {
        if (isset($server['HTTP_X_WEBHOOK_SIGNATURE_512'])) {
            return (string) $server['HTTP_X_WEBHOOK_SIGNATURE_512'];
        }
        foreach ($server as $k => $v) {
            if (strtolower((string) $k) === 'x-webhook-signature-512') {
                return is_array($v) ? (string) ($v[0] ?? '') : (string) $v;
            }
        }
        return null;
    }

    public static function t(array $params, string $key): string
    {
        $lang = strtolower((string) ($params['clientdetails']['language'] ?? ''));
        $code = ($lang === '' || str_starts_with($lang, 'vi')) ? 'vi' : 'en';
        return self::TEXT[$code][$key] ?? self::TEXT['en'][$key] ?? $key;
    }

    private static function h(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ------------------------------------------------------------------ invoice page (_link)

    /**
     * Render the invoice payment area. With ?hitechpay_method=<key> on the invoice page,
     * creates the payment (or payment link) and redirects or shows the VietQR code.
     *
     * @param array $request normally $_GET
     * @param array|null $session normally $_SESSION (by reference) — used to reuse a pending VietQR
     */
    public static function link(array $params, array $request, ?array &$session = null): string
    {
        $invoiceId = (int) $params['invoiceid'];
        $methods = self::enabledMethods($params);
        $chosen = (string) ($request['hitechpay_method'] ?? '');

        if (!empty($request['hitechpay_pending'])) {
            return '<div class="alert alert-info">' . self::h(self::t($params, 'pending')) . '</div>' . self::buttons($params, $methods);
        }
        if ($chosen === '' || !in_array($chosen, $methods, true) || (int) ($request['id'] ?? 0) !== $invoiceId) {
            return self::buttons($params, $methods);
        }

        try {
            $payment = self::reusablePayment($params, $chosen, $session) ?? self::createPayment($params, $chosen);
        } catch (HiTechPayException|\InvalidArgumentException $e) {
            if (function_exists('logTransaction')) {
                logTransaction($params['name'] ?? 'HiTechPay', ['invoiceid' => $invoiceId, 'method' => $chosen, 'error' => $e->getMessage()], 'Create Payment Error');
            }
            return '<div class="alert alert-danger">' . self::h(self::t($params, 'error')) . '</div>' . self::buttons($params, $methods);
        }

        if ($chosen === self::METHOD_VIETQR && $session !== null && isset($payment['payment_id'])) {
            $session['hitechpay'][$invoiceId][$chosen] = [
                'payment_id' => $payment['payment_id'],
                'amount' => $payment['amount'] ?? null,
                'currency' => $payment['currency'] ?? null,
                'expires' => time() + self::REUSE_SECONDS,
            ];
        }

        return self::renderNextStep($params, $payment);
    }

    /** Payment creation request for a method. */
    public static function buildPaymentRequest(array $params, string $method): array
    {
        $invoiceId = (int) $params['invoiceid'];
        $currency = strtoupper((string) $params['currency']);
        $client = $params['clientdetails'] ?? [];
        $name = trim(($client['firstname'] ?? '') . ' ' . ($client['lastname'] ?? ''));

        $req = [
            'amount' => self::toMinor($params['amount'], $currency),
            'currency' => $currency,
            'description' => mb_substr((string) ($params['description'] ?? ('Invoice #' . $invoiceId)), 0, 255),
            'return_url' => self::returnUrl($params),
            'metadata' => [
                'source' => 'whmcs',
                'whmcs_invoice_id' => (string) $invoiceId,
                // Invoice currency / amount before WHMCS "Convert To For Processing".
                'whmcs_currency' => strtoupper((string) ($params['basecurrency'] ?? $currency)),
                'whmcs_amount' => (string) ($params['basecurrencyamount'] ?? $params['amount']),
            ],
        ];
        if (!empty($client['email'])) {
            $req['email'] = (string) $client['email'];
        }
        if ($name !== '') {
            $req['name'] = $name;
        }
        if (!empty($client['userid'])) {
            $req['customer_id'] = 'whmcs_' . (int) $client['userid'];
        }
        if (trim((string) ($params['profileId'] ?? '')) !== '') {
            $req['profile_id'] = trim((string) $params['profileId']);
        }

        switch ($method) {
            case self::METHOD_VIETQR:
                $req += ['confirm' => true] + VietNam::vietQr();
                $connector = trim((string) ($params['vietQrConnector'] ?? ''));
                break;
            case self::METHOD_MOMO:
                $req += ['confirm' => true] + VietNam::momo();
                $connector = 'momo';
                break;
            case self::METHOD_REDIRECT:
                $req += ['confirm' => true] + VietNam::localBankRedirect();
                $connector = trim((string) ($params['bankPageConnector'] ?? ''));
                break;
            case self::METHOD_CARD:
                // Hosted HiTechPay payment page: card data never touches WHMCS.
                $req += ['confirm' => false, 'payment_link' => true];
                $connector = '';
                break;
            default:
                throw new \InvalidArgumentException("Unknown HiTechPay method: {$method}");
        }
        if ($connector !== '') {
            $req['connector'] = [$connector];
        }
        return $req;
    }

    public static function createPayment(array $params, string $method): array
    {
        return self::client($params)->payments->create(self::buildPaymentRequest($params, $method));
    }

    private static function reusablePayment(array $params, string $method, ?array $session): ?array
    {
        if ($method !== self::METHOD_VIETQR || $session === null) {
            return null;
        }
        $saved = $session['hitechpay'][(int) $params['invoiceid']][$method] ?? null;
        if (!is_array($saved) || ($saved['expires'] ?? 0) < time()
            || (int) ($saved['amount'] ?? -1) !== self::toMinor($params['amount'], (string) $params['currency'])
            || strtoupper((string) ($saved['currency'] ?? '')) !== strtoupper((string) $params['currency'])) {
            return null;
        }
        try {
            $payment = self::client($params)->payments->retrieve((string) $saved['payment_id']);
        } catch (HiTechPayException) {
            return null;
        }
        return ($payment['status'] ?? '') === 'requires_customer_action' && isset($payment['next_action']) ? $payment : null;
    }

    public static function renderNextStep(array $params, array $payment): string
    {
        $next = $payment['next_action'] ?? null;
        $type = is_array($next) ? ($next['type'] ?? '') : '';

        if (!empty($payment['payment_link']['link'])) {
            return self::redirectHtml($params, (string) $payment['payment_link']['link']);
        }
        if ($type === 'redirect_to_url' && !empty($next['redirect_to_url'])) {
            return self::redirectHtml($params, (string) $next['redirect_to_url']);
        }
        if ($type === 'qr_code_information') {
            $img = $next['image_data_url'] ?? $next['qr_code_url'] ?? null;
            $currency = strtoupper((string) ($payment['currency'] ?? $params['currency']));
            $amount = $currency === 'VND'
                ? VietNam::formatVnd((int) ($payment['amount'] ?? self::toMinor($params['amount'], $currency)))
                : number_format((float) $params['amount'], 2) . ' ' . $currency;
            $html = '<div class="hitechpay-vietqr" style="text-align:center">'
                . '<p><strong>' . self::h(self::t($params, 'scan')) . '</strong></p>';
            if ($img) {
                $html .= '<img src="' . self::h($img) . '" alt="VietQR" style="width:240px;max-width:100%;height:auto" />';
            }
            $html .= '<p>' . self::h(self::t($params, 'amount')) . ': <strong>' . self::h($amount) . '</strong></p>';
            if (!empty($next['display_to_timestamp'])) {
                $ts = (float) $next['display_to_timestamp'];
                while ($ts > 1e11) { // ns / µs / ms -> seconds
                    $ts /= 1000;
                }
                $ts = (int) $ts;
                $html .= '<p><small>' . self::h(self::t($params, 'expires')) . ' ' . self::h(gmdate('H:i d/m/Y', $ts + 7 * 3600)) . ' (GMT+7)</small></p>';
            }
            $check = self::returnUrl($params) . '&payment_id=' . rawurlencode((string) ($payment['payment_id'] ?? ''));
            $html .= '<p><a class="btn btn-primary" href="' . self::h($check) . '">' . self::h(self::t($params, 'paid')) . '</a></p>'
                . '<p><a href="' . self::h(self::invoiceUrl($params)) . '">' . self::h(self::t($params, 'other')) . '</a></p>'
                . '</div>';
            return $html;
        }
        // Already final (e.g. succeeded instantly) or unknown next action: let the return handler sync it.
        if (!empty($payment['payment_id'])) {
            return self::redirectHtml($params, self::returnUrl($params) . '&payment_id=' . rawurlencode((string) $payment['payment_id']));
        }
        return '<div class="alert alert-danger">' . self::h(self::t($params, 'error')) . '</div>';
    }

    private static function buttons(array $params, array $methods): string
    {
        $html = '<form method="get" action="' . self::h(self::systemUrl($params) . 'viewinvoice.php') . '" class="hitechpay-methods">'
            . '<input type="hidden" name="id" value="' . (int) $params['invoiceid'] . '" />';
        foreach ($methods as $m) {
            $html .= '<button type="submit" name="hitechpay_method" value="' . self::h($m) . '" class="btn btn-primary" style="margin:4px">'
                . self::h(self::t($params, 'pay_with') . ' ' . self::t($params, $m)) . '</button> ';
        }
        return $html . '</form>';
    }

    private static function redirectHtml(array $params, string $url): string
    {
        // _link output is rendered inside the invoice template, so redirect client-side.
        $u = self::h($url);
        return '<p>' . self::h(self::t($params, 'redirecting')) . '</p>'
            . '<p><a class="btn btn-primary" href="' . $u . '">' . self::h(self::t($params, 'continue')) . '</a></p>'
            . '<script>window.location.href = ' . json_encode($url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
    }

    public static function systemUrl(array $params): string
    {
        return rtrim((string) ($params['systemurl'] ?? ''), '/') . '/';
    }

    public static function invoiceUrl(array $params, string $suffix = ''): string
    {
        return self::systemUrl($params) . 'viewinvoice.php?id=' . (int) $params['invoiceid'] . $suffix;
    }

    public static function returnUrl(array $params): string
    {
        return self::systemUrl($params) . 'modules/gateways/callback/' . self::MODULE . '.php?return=1&invoiceid=' . (int) $params['invoiceid'];
    }

    // ------------------------------------------------------------------ callback (webhook + return)

    /**
     * Handle a HiTechPay webhook POST.
     *
     * @return array{status:int, body:string}
     */
    public static function handleWebhook(array $gateway, string $rawBody, ?string $signature): array
    {
        $name = (string) ($gateway['name'] ?? 'HiTechPay');
        if (!self::verifySignature($rawBody, $signature, (string) ($gateway['webhookHashKey'] ?? ''))) {
            logTransaction($name, ['signature' => $signature === null ? '(missing)' : 'invalid', 'body' => mb_substr($rawBody, 0, 2000)], 'Invalid Signature');
            return ['status' => 400, 'body' => '{"error":"invalid signature"}'];
        }
        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            return ['status' => 400, 'body' => '{"error":"invalid payload"}'];
        }
        $object = $event['content']['object'] ?? null;
        $type = $event['content']['type'] ?? '';
        if ($type !== 'payment_details' || !is_array($object)) {
            logTransaction($name, $event, 'Ignored (' . ($event['event_type'] ?? 'unknown') . ')');
            return ['status' => 200, 'body' => '{"received":true}'];
        }
        if (!isset($object['metadata']['whmcs_invoice_id'])) {
            logTransaction($name, $event, 'Ignored (not a WHMCS payment)');
            return ['status' => 200, 'body' => '{"received":true}'];
        }
        $outcome = self::applyPayment($gateway, $object, 'webhook ' . ($event['event_type'] ?? ''));
        return ['status' => 200, 'body' => json_encode(['received' => true, 'outcome' => $outcome])];
    }

    /**
     * Handle the customer returning from the payment page / clicking "I have paid".
     * The payment is re-read from the API with the secret key, so query parameters are not trusted.
     *
     * @return string URL to redirect the customer to
     */
    public static function handleReturn(array $gateway, array $query): string
    {
        $invoiceId = (int) ($query['invoiceid'] ?? 0);
        $invoice = ['invoiceid' => $invoiceId, 'systemurl' => $gateway['systemurl'] ?? ''];
        $paymentId = trim((string) ($query['payment_id'] ?? ''));
        if ($invoiceId <= 0 || $paymentId === '') {
            return self::invoiceUrl($invoice);
        }
        try {
            $payment = self::client($gateway)->payments->retrieve($paymentId, ['force_sync' => true]);
        } catch (HiTechPayException $e) {
            logTransaction((string) $gateway['name'], ['payment_id' => $paymentId, 'error' => $e->getMessage()], 'Return Sync Error');
            return self::invoiceUrl($invoice, '&hitechpay_pending=1');
        }
        if ((int) ($payment['metadata']['whmcs_invoice_id'] ?? 0) !== $invoiceId) {
            logTransaction((string) $gateway['name'], ['payment_id' => $paymentId, 'invoiceid' => $invoiceId], 'Return Invoice Mismatch');
            return self::invoiceUrl($invoice, '&paymentfailed=true');
        }
        $outcome = self::applyPayment($gateway, $payment, 'return');
        return match ($outcome) {
            'success', 'duplicate' => self::invoiceUrl($invoice, '&paymentsuccess=true'),
            'failed' => self::invoiceUrl($invoice, '&paymentfailed=true'),
            default => self::invoiceUrl($invoice, '&hitechpay_pending=1'),
        };
    }

    /**
     * Record a payment object against its WHMCS invoice.
     * Duplicate protection: GetTransactions lookup + checkCbTransID (which stops the script
     * when the transaction id is already recorded).
     *
     * @return string success|duplicate|failed|pending|unknown
     */
    public static function applyPayment(array $gateway, array $payment, string $source): string
    {
        $name = (string) ($gateway['name'] ?? 'HiTechPay');
        $module = (string) ($gateway['paymentmethod'] ?? self::MODULE);
        $paymentId = (string) ($payment['payment_id'] ?? '');
        $invoiceId = checkCbInvoiceID((int) ($payment['metadata']['whmcs_invoice_id'] ?? 0), $name);
        $outcome = self::mapStatus($payment['status'] ?? null);
        $log = ['source' => $source, 'payment_id' => $paymentId, 'status' => $payment['status'] ?? null, 'invoiceid' => $invoiceId, 'payment' => $payment];

        if ($outcome === 'failed') {
            logTransaction($name, $log, 'Failure');
            return 'failed';
        }
        if ($outcome !== 'success') {
            logTransaction($name, $log, $outcome === 'pending' ? 'Pending' : 'Unknown Status');
            return $outcome;
        }
        if ($paymentId === '') {
            logTransaction($name, $log, 'Missing payment_id');
            return 'unknown';
        }
        if (self::transactionExists($paymentId)) {
            return 'duplicate';
        }
        checkCbTransID($paymentId); // stops here (exit) if the transaction was already recorded

        $amount = self::invoiceAmount($payment);
        addInvoicePayment($invoiceId, $paymentId, $amount, 0, $module);
        logTransaction($name, $log + ['recorded_amount' => $amount], 'Success');
        return 'success';
    }

    /** Amount to record on the invoice, in the invoice currency. */
    public static function invoiceAmount(array $payment): float
    {
        $currency = strtoupper((string) ($payment['currency'] ?? ''));
        $requested = (float) ($payment['amount'] ?? 0);
        $received = (float) ($payment['amount_received'] ?? $payment['amount_capturable'] ?? 0);
        if ($received <= 0) {
            $received = $requested;
        }
        $invoiceCurrency = strtoupper((string) ($payment['metadata']['whmcs_currency'] ?? $currency));
        if ($invoiceCurrency === $currency || !isset($payment['metadata']['whmcs_amount']) || $requested <= 0) {
            return self::fromMinor($received, $currency);
        }
        // Paid in a converted currency ("Convert To For Processing"): pro-rate the original invoice amount.
        return round((float) $payment['metadata']['whmcs_amount'] * min(1.0, $received / $requested), 2);
    }

    private static function transactionExists(string $transId): bool
    {
        if (!function_exists('localAPI')) {
            return false;
        }
        $res = localAPI('GetTransactions', ['transid' => $transId]);
        return (int) ($res['totalresults'] ?? 0) > 0;
    }

    // ------------------------------------------------------------------ refund

    /** WHMCS refund: POST /refunds. */
    public static function refund(array $params): array
    {
        $currency = strtoupper((string) $params['currency']);
        $request = [
            'payment_id' => (string) $params['transid'],
            'amount' => self::toMinor($params['amount'], $currency),
            'reason' => 'WHMCS refund, invoice #' . (int) ($params['invoiceid'] ?? 0),
            'refund_type' => 'instant',
            'metadata' => ['source' => 'whmcs', 'whmcs_invoice_id' => (string) (int) ($params['invoiceid'] ?? 0)],
        ];
        try {
            $refund = self::client($params)->refunds->create($request);
        } catch (HiTechPayException $e) {
            return [
                'status' => 'error',
                'rawdata' => ['request' => $request, 'error' => $e->getMessage(), 'code' => $e->getErrorCode(), 'http' => $e->getStatus()],
            ];
        }
        $status = self::mapRefundStatus($refund['status'] ?? null);
        $result = ['status' => $status, 'rawdata' => $refund];
        if ($status === 'success') {
            $result['transid'] = (string) ($refund['refund_id'] ?? '');
            $result['fees'] = 0;
        }
        return $result;
    }
}
