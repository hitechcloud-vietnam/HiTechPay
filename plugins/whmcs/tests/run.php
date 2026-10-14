<?php

/**
 * Dependency-free tests for the HiTechPay WHMCS gateway module: php plugins/whmcs/tests/run.php
 *
 * WHMCS functions (logTransaction, checkCbInvoiceID, checkCbTransID, addInvoicePayment, localAPI)
 * are mocked; HTTP goes through a mock implementation of the SDK's Transport interface.
 */

declare(strict_types=1);

define('WHMCS', true);

require __DIR__ . '/../modules/gateways/hitechpay.php';

use HiTechPay\Http\Transport;

// ---------------------------------------------------------------- WHMCS mocks

/** Simulates the die()/exit that WHMCS performs inside checkCb* helpers. */
final class WhmcsExit extends \RuntimeException
{
}

final class Whmcs
{
    public static array $logs = [];
    public static array $payments = [];      // addInvoicePayment calls
    public static array $invoices = [];      // valid invoice ids
    public static array $ledger = [];        // recorded transaction ids
    public static bool $apiSeesLedger = true; // localAPI GetTransactions visibility (false = race)

    public static function reset(): void
    {
        self::$logs = [];
        self::$payments = [];
        self::$invoices = [1001 => true, 1002 => true];
        self::$ledger = [];
        self::$apiSeesLedger = true;
    }

    public static function lastLogStatus(): ?string
    {
        return self::$logs ? self::$logs[count(self::$logs) - 1]['status'] : null;
    }
}

function logTransaction($gatewayName, $data, $status): void
{
    Whmcs::$logs[] = ['gateway' => $gatewayName, 'data' => $data, 'status' => $status];
}

function checkCbInvoiceID($invoiceId, $gatewayName)
{
    if (!isset(Whmcs::$invoices[(int) $invoiceId])) {
        throw new WhmcsExit('invalid invoice ' . $invoiceId);
    }
    return (int) $invoiceId;
}

function checkCbTransID($transId): void
{
    if (isset(Whmcs::$ledger[$transId])) {
        throw new WhmcsExit('duplicate transaction ' . $transId);
    }
}

function addInvoicePayment($invoiceId, $transId, $amount, $fee, $gateway): bool
{
    Whmcs::$payments[] = compact('invoiceId', 'transId', 'amount', 'fee', 'gateway');
    Whmcs::$ledger[$transId] = true;
    return true;
}

function localAPI($command, $values = [], $adminUser = '')
{
    if ($command === 'GetTransactions') {
        $found = Whmcs::$apiSeesLedger && isset(Whmcs::$ledger[$values['transid'] ?? '']);
        return ['result' => 'success', 'totalresults' => $found ? 1 : 0];
    }
    return ['result' => 'error', 'message' => 'not mocked'];
}

// ---------------------------------------------------------------- HTTP mock

final class MockTransport implements Transport
{
    public array $calls = [];
    private int $i = 0;

    public function __construct(private array $responses)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body === null ? null : json_decode($body, true)];
        $r = $this->responses[min($this->i++, count($this->responses) - 1)];
        return ['status' => $r['status'], 'headers' => [], 'body' => json_encode($r['body'] ?? [])];
    }
}

function mock(array ...$responses): MockTransport
{
    $t = new MockTransport($responses);
    HiTechPayWhmcs::$transport = $t;
    return $t;
}

// ---------------------------------------------------------------- mini harness

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function check(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new \AssertionError($msg);
    }
}

function eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    check($expected === $actual, ($msg ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function it(string $name, callable $fn): void
{
    Whmcs::reset();
    HiTechPayWhmcs::$transport = null;
    try {
        $fn();
        $GLOBALS['pass']++;
        echo "ok   - $name\n";
    } catch (\Throwable $e) {
        $GLOBALS['fail']++;
        echo "FAIL - $name\n       " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

const HASH_KEY = 'prh_test_key';

function gateway(array $extra = []): array
{
    return $extra + [
        'type' => 'Invoices',
        'name' => 'HiTechPay',
        'paymentmethod' => 'hitechpay',
        'systemurl' => 'https://billing.example.vn/',
        'apiKey' => 'snd_secret',
        'publishableKey' => 'pk_snd_x',
        'webhookHashKey' => HASH_KEY,
        'sandbox' => 'on',
    ];
}

function invoiceParams(array $extra = []): array
{
    return $extra + gateway() + [
        'invoiceid' => 1001,
        'description' => 'Hoá đơn #1001',
        'amount' => '150000.00',
        'currency' => 'VND',
        'clientdetails' => ['userid' => 7, 'firstname' => 'Nguyễn', 'lastname' => 'Văn A', 'email' => 'a@example.vn', 'language' => 'vietnamese'],
        'enableVietQr' => 'on',
        'enableMomo' => 'on',
        'enableBankPage' => 'on',
        'enableCards' => 'on',
    ];
}

function payment(string $status, array $extra = []): array
{
    return $extra + [
        'payment_id' => 'pay_abc123',
        'status' => $status,
        'amount' => 150000,
        'amount_received' => $status === 'succeeded' ? 150000 : null,
        'currency' => 'VND',
        'metadata' => ['source' => 'whmcs', 'whmcs_invoice_id' => '1001', 'whmcs_currency' => 'VND', 'whmcs_amount' => '150000.00'],
    ];
}

function webhookBody(string $eventType, array $object, string $contentType = 'payment_details'): string
{
    return json_encode([
        'merchant_id' => 'merchant_1',
        'event_id' => 'evt_' . md5($eventType . json_encode($object)),
        'event_type' => $eventType,
        'content' => ['type' => $contentType, 'object' => $object],
        'timestamp' => '2026-10-14T03:00:00Z',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function sign(string $raw, string $key = HASH_KEY): string
{
    return hash_hmac('sha512', $raw, $key);
}

// ---------------------------------------------------------------- signature verification

it('verifySignature accepts a valid HMAC-SHA512 hex of the raw body', function () {
    $raw = webhookBody('payment_succeeded', payment('succeeded'));
    check(HiTechPayWhmcs::verifySignature($raw, sign($raw), HASH_KEY), 'valid');
    check(HiTechPayWhmcs::verifySignature($raw, strtoupper(sign($raw)), HASH_KEY), 'upper-case hex');
    check(HiTechPayWhmcs::verifySignature($raw, ' ' . sign($raw) . "\n", HASH_KEY), 'surrounding whitespace');
});

it('verifySignature rejects wrong key, tampered body, missing/short/sha256 signatures, empty key', function () {
    $raw = webhookBody('payment_succeeded', payment('succeeded'));
    check(!HiTechPayWhmcs::verifySignature($raw, sign($raw, 'other'), HASH_KEY), 'wrong key');
    check(!HiTechPayWhmcs::verifySignature($raw . ' ', sign($raw), HASH_KEY), 'tampered body');
    check(!HiTechPayWhmcs::verifySignature($raw, null, HASH_KEY), 'missing');
    check(!HiTechPayWhmcs::verifySignature($raw, '', HASH_KEY), 'empty');
    check(!HiTechPayWhmcs::verifySignature($raw, hash_hmac('sha256', $raw, HASH_KEY), HASH_KEY), 'sha256 not accepted for -512 header');
    check(!HiTechPayWhmcs::verifySignature($raw, sign($raw, ''), ''), 'empty hash key never verifies');
});

it('signatureFromServer reads X-Webhook-Signature-512 from $_SERVER or header arrays', function () {
    eq('abc', HiTechPayWhmcs::signatureFromServer(['HTTP_X_WEBHOOK_SIGNATURE_512' => 'abc']));
    eq('def', HiTechPayWhmcs::signatureFromServer(['X-Webhook-Signature-512' => ['def']]));
    eq(null, HiTechPayWhmcs::signatureFromServer(['HTTP_X_WEBHOOK_SIGNATURE_256' => 'x']));
});

// ---------------------------------------------------------------- status mapping

it('mapStatus maps every Hyperswitch intent status', function () {
    $cases = [
        'succeeded' => 'success', 'partially_captured' => 'success',
        'failed' => 'failed', 'cancelled' => 'failed', 'expired' => 'failed',
        'processing' => 'pending', 'requires_customer_action' => 'pending', 'requires_payment_method' => 'pending',
        'requires_confirmation' => 'pending', 'requires_capture' => 'pending', 'requires_merchant_action' => 'pending',
        'partially_captured_and_capturable' => 'pending',
        'conflicted' => 'unknown', '' => 'unknown', 'SUCCEEDED' => 'success',
    ];
    foreach ($cases as $in => $out) {
        eq($out, HiTechPayWhmcs::mapStatus($in), "status '$in'");
    }
    eq('unknown', HiTechPayWhmcs::mapStatus(null));
});

it('mapRefundStatus maps refund statuses to WHMCS results', function () {
    eq('success', HiTechPayWhmcs::mapRefundStatus('succeeded'));
    eq('success', HiTechPayWhmcs::mapRefundStatus('pending'));
    eq('declined', HiTechPayWhmcs::mapRefundStatus('failed'));
    eq('declined', HiTechPayWhmcs::mapRefundStatus('review'));
    eq('error', HiTechPayWhmcs::mapRefundStatus(null));
});

it('amount conversion: VND zero-decimal, USD two, KWD three', function () {
    eq(150000, HiTechPayWhmcs::toMinor('150000.00', 'VND'));
    eq(1999, HiTechPayWhmcs::toMinor('19.99', 'usd'));
    eq(1234, HiTechPayWhmcs::toMinor('1.234', 'KWD'));
    eq(150000.0, HiTechPayWhmcs::fromMinor(150000, 'VND'));
    eq(19.99, HiTechPayWhmcs::fromMinor(1999, 'USD'));
});

// ---------------------------------------------------------------- webhook

it('webhook payment_succeeded records the payment once (addInvoicePayment + logTransaction Success)', function () {
    $raw = webhookBody('payment_succeeded', payment('succeeded'));
    $res = HiTechPayWhmcs::handleWebhook(gateway(), $raw, sign($raw));
    eq(200, $res['status']);
    eq(1, count(Whmcs::$payments));
    eq(['invoiceId' => 1001, 'transId' => 'pay_abc123', 'amount' => 150000.0, 'fee' => 0, 'gateway' => 'hitechpay'], Whmcs::$payments[0]);
    eq('Success', Whmcs::lastLogStatus());
});

it('duplicate webhook is ignored (GetTransactions lookup)', function () {
    $raw = webhookBody('payment_succeeded', payment('succeeded'));
    HiTechPayWhmcs::handleWebhook(gateway(), $raw, sign($raw));
    $res = HiTechPayWhmcs::handleWebhook(gateway(), $raw, sign($raw));
    eq(200, $res['status']);
    check(str_contains($res['body'], 'duplicate'), 'outcome duplicate');
    eq(1, count(Whmcs::$payments), 'still one payment');
});

it('duplicate webhook racing past GetTransactions is stopped by checkCbTransID', function () {
    $raw = webhookBody('payment_succeeded', payment('succeeded'));
    HiTechPayWhmcs::handleWebhook(gateway(), $raw, sign($raw));
    Whmcs::$apiSeesLedger = false;
    $stopped = false;
    try {
        HiTechPayWhmcs::handleWebhook(gateway(), $raw, sign($raw));
    } catch (WhmcsExit $e) {
        $stopped = str_contains($e->getMessage(), 'duplicate');
    }
    check($stopped, 'checkCbTransID stopped the script');
    eq(1, count(Whmcs::$payments));
});

it('webhook with invalid signature returns 400 and records nothing', function () {
    $raw = webhookBody('payment_succeeded', payment('succeeded'));
    $res = HiTechPayWhmcs::handleWebhook(gateway(), $raw, sign($raw, 'attacker'));
    eq(400, $res['status']);
    eq([], Whmcs::$payments);
    eq('Invalid Signature', Whmcs::lastLogStatus());
    $res = HiTechPayWhmcs::handleWebhook(gateway(), $raw, null);
    eq(400, $res['status']);
});

it('webhook payment_failed logs Failure without recording a payment', function () {
    $raw = webhookBody('payment_failed', payment('failed', ['error_message' => 'Khách huỷ giao dịch']));
    $res = HiTechPayWhmcs::handleWebhook(gateway(), $raw, sign($raw));
    eq(200, $res['status']);
    eq([], Whmcs::$payments);
    eq('Failure', Whmcs::lastLogStatus());
});

it('webhook payment_processing logs Pending', function () {
    $raw = webhookBody('payment_processing', payment('processing'));
    HiTechPayWhmcs::handleWebhook(gateway(), $raw, sign($raw));
    eq([], Whmcs::$payments);
    eq('Pending', Whmcs::lastLogStatus());
});

it('webhook for a non-WHMCS payment or refund event is acknowledged and ignored', function () {
    $p = payment('succeeded');
    unset($p['metadata']['whmcs_invoice_id']);
    $raw = webhookBody('payment_succeeded', $p);
    eq(200, HiTechPayWhmcs::handleWebhook(gateway(), $raw, sign($raw))['status']);
    $raw = webhookBody('refund_succeeded', ['refund_id' => 'ref_1', 'payment_id' => 'pay_abc123', 'status' => 'succeeded'], 'refund_details');
    eq(200, HiTechPayWhmcs::handleWebhook(gateway(), $raw, sign($raw))['status']);
    eq([], Whmcs::$payments);
});

it('webhook for an unknown invoice stops at checkCbInvoiceID', function () {
    $p = payment('succeeded');
    $p['metadata']['whmcs_invoice_id'] = '9999';
    $raw = webhookBody('payment_succeeded', $p);
    $stopped = false;
    try {
        HiTechPayWhmcs::handleWebhook(gateway(), $raw, sign($raw));
    } catch (WhmcsExit) {
        $stopped = true;
    }
    check($stopped, 'stopped');
    eq([], Whmcs::$payments);
});

it('webhook amount: USD minor units and converted-currency invoices', function () {
    $raw = webhookBody('payment_succeeded', payment('succeeded', [
        'payment_id' => 'pay_usd', 'currency' => 'USD', 'amount' => 1999, 'amount_received' => 1999,
        'metadata' => ['whmcs_invoice_id' => '1002', 'whmcs_currency' => 'USD', 'whmcs_amount' => '19.99'],
    ]));
    HiTechPayWhmcs::handleWebhook(gateway(), $raw, sign($raw));
    eq(19.99, Whmcs::$payments[0]['amount']);

    // Invoice in USD, processed in VND ("Convert To For Processing"): record the USD invoice amount.
    $raw = webhookBody('payment_succeeded', payment('succeeded', [
        'payment_id' => 'pay_conv', 'currency' => 'VND', 'amount' => 500000, 'amount_received' => 500000,
        'metadata' => ['whmcs_invoice_id' => '1002', 'whmcs_currency' => 'USD', 'whmcs_amount' => '20.00'],
    ]));
    HiTechPayWhmcs::handleWebhook(gateway(), $raw, sign($raw));
    eq(20.0, Whmcs::$payments[1]['amount']);
});

// ---------------------------------------------------------------- return flow

it('return: re-reads the payment with force_sync and records it, then redirects to paymentsuccess', function () {
    $t = mock(['status' => 200, 'body' => payment('succeeded')]);
    $url = HiTechPayWhmcs::handleReturn(gateway(), ['return' => '1', 'invoiceid' => '1001', 'payment_id' => 'pay_abc123', 'status' => 'failed']);
    eq('https://billing.example.vn/viewinvoice.php?id=1001&paymentsuccess=true', $url);
    eq('https://sandbox-api.hitechpay.vn/payments/pay_abc123?force_sync=true', $t->calls[0]['url']);
    eq('snd_secret', $t->calls[0]['headers']['api-key']);
    eq(1, count(Whmcs::$payments));
});

it('return after the webhook already recorded the payment does not duplicate it', function () {
    $raw = webhookBody('payment_succeeded', payment('succeeded'));
    HiTechPayWhmcs::handleWebhook(gateway(), $raw, sign($raw));
    mock(['status' => 200, 'body' => payment('succeeded')]);
    $url = HiTechPayWhmcs::handleReturn(gateway(), ['invoiceid' => '1001', 'payment_id' => 'pay_abc123']);
    check(str_ends_with($url, 'paymentsuccess=true'), $url);
    eq(1, count(Whmcs::$payments));
});

it('return: pending, failed and invoice mismatch', function () {
    mock(['status' => 200, 'body' => payment('requires_customer_action')]);
    check(str_ends_with(HiTechPayWhmcs::handleReturn(gateway(), ['invoiceid' => '1001', 'payment_id' => 'pay_abc123']), 'hitechpay_pending=1'), 'pending');
    mock(['status' => 200, 'body' => payment('failed')]);
    check(str_ends_with(HiTechPayWhmcs::handleReturn(gateway(), ['invoiceid' => '1001', 'payment_id' => 'pay_abc123']), 'paymentfailed=true'), 'failed');
    mock(['status' => 200, 'body' => payment('succeeded')]);
    check(str_ends_with(HiTechPayWhmcs::handleReturn(gateway(), ['invoiceid' => '1002', 'payment_id' => 'pay_abc123']), 'paymentfailed=true'), 'mismatch');
    eq([], Whmcs::$payments);
    eq('Return Invoice Mismatch', Whmcs::lastLogStatus());
});

// ---------------------------------------------------------------- _link

it('hitechpay_MetaData / hitechpay_config expose the expected fields', function () {
    $meta = hitechpay_MetaData();
    eq(true, $meta['DisableLocalCreditCardInput']);
    $cfg = hitechpay_config();
    foreach (['FriendlyName', 'apiKey', 'publishableKey', 'webhookHashKey', 'sandbox', 'enableVietQr', 'enableMomo', 'enableBankPage', 'enableCards'] as $k) {
        check(isset($cfg[$k]), "config field $k");
    }
    eq('HiTechPay', $cfg['FriendlyName']['Value']);
});

it('link without a choice renders one button per enabled method and makes no API call', function () {
    $t = mock(['status' => 500, 'body' => []]);
    $html = hitechpay_link(invoiceParams(['enableCards' => '']));
    eq([], $t->calls);
    foreach (['vietqr', 'momo', 'bankpage'] as $m) {
        check(str_contains($html, 'value="' . $m . '"'), "button $m");
    }
    check(!str_contains($html, 'value="card"'), 'cards disabled');
    check(str_contains($html, 'Thanh toán bằng'), 'Vietnamese label');
    check(str_contains(hitechpay_link(invoiceParams(['enableVietQr' => '', 'enableMomo' => '', 'enableBankPage' => '', 'enableCards' => ''])), 'value="vietqr"'), 'VietQR fallback');
});

it('link VietQR creates a confirmed viet_qr payment and renders the QR', function () {
    $t = mock(['status' => 200, 'body' => payment('requires_customer_action', [
        'next_action' => ['type' => 'qr_code_information', 'image_data_url' => 'https://qr.sepay.vn/img?acc=1&amount=150000', 'display_to_timestamp' => 1791950400000],
    ])]);
    $session = [];
    $html = HiTechPayWhmcs::link(invoiceParams(['vietQrConnector' => 'sepay']), ['id' => '1001', 'hitechpay_method' => 'vietqr'], $session);
    $call = $t->calls[0];
    eq('POST', $call['method']);
    eq('https://sandbox-api.hitechpay.vn/payments', $call['url']);
    $b = $call['body'];
    eq(150000, $b['amount']);
    eq('VND', $b['currency']);
    eq(true, $b['confirm']);
    eq('real_time_payment', $b['payment_method']);
    eq('viet_qr', $b['payment_method_type']);
    eq(['sepay'], $b['connector']);
    eq('1001', $b['metadata']['whmcs_invoice_id']);
    eq('https://billing.example.vn/modules/gateways/callback/hitechpay.php?return=1&invoiceid=1001', $b['return_url']);
    eq('whmcs_7', $b['customer_id']);
    check(str_contains($html, 'https://qr.sepay.vn/img?acc=1&amp;amount=150000'), 'QR image');
    check(str_contains($html, '150.000 ₫'), 'VND amount');
    check(str_contains($html, 'payment_id=pay_abc123'), 'check-status link');
    eq('pay_abc123', $session['hitechpay'][1001]['vietqr']['payment_id']);

    // Reload: pending payment is reused instead of creating a new one.
    $t2 = mock(['status' => 200, 'body' => payment('requires_customer_action', ['next_action' => ['type' => 'qr_code_information', 'qr_code_url' => 'https://img.vietqr.io/x.png']])]);
    $html2 = HiTechPayWhmcs::link(invoiceParams(), ['id' => '1001', 'hitechpay_method' => 'vietqr'], $session);
    eq('GET', $t2->calls[0]['method']);
    eq(1, count($t2->calls));
    check(str_contains($html2, 'https://img.vietqr.io/x.png'), 'reused QR');
});

it('link MoMo / ZaloPay-VNPAY redirect, cards use a payment link', function () {
    $t = mock(['status' => 200, 'body' => payment('requires_customer_action', ['next_action' => ['type' => 'redirect_to_url', 'redirect_to_url' => 'https://test-payment.momo.vn/pay?x=1']])]);
    $html = HiTechPayWhmcs::link(invoiceParams(), ['id' => '1001', 'hitechpay_method' => 'momo']);
    eq(['momo'], $t->calls[0]['body']['connector']);
    eq('wallet', $t->calls[0]['body']['payment_method']);
    check(str_contains($html, 'window.location.href = "https:\/\/test-payment.momo.vn\/pay?x=1"') || str_contains($html, 'window.location.href = "https://test-payment.momo.vn/pay?x=1"'), 'momo redirect: ' . $html);

    $t = mock(['status' => 200, 'body' => payment('requires_customer_action', ['next_action' => ['type' => 'redirect_to_url', 'redirect_to_url' => 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html?a=1']])]);
    HiTechPayWhmcs::link(invoiceParams(['bankPageConnector' => 'vnpay']), ['id' => '1001', 'hitechpay_method' => 'bankpage']);
    eq('local_bank_redirect', $t->calls[0]['body']['payment_method_type']);
    eq(['vnpay'], $t->calls[0]['body']['connector']);

    $t = mock(['status' => 200, 'body' => payment('requires_payment_method', ['payment_link' => ['link' => 'https://pay.hitechpay.vn/payment_link/m/pay_abc123', 'payment_link_id' => 'plink_1']])]);
    $html = HiTechPayWhmcs::link(invoiceParams(), ['id' => '1001', 'hitechpay_method' => 'card']);
    eq(true, $t->calls[0]['body']['payment_link']);
    eq(false, $t->calls[0]['body']['confirm']);
    check(!isset($t->calls[0]['body']['payment_method']), 'no payment_method for payment link');
    check(str_contains($html, 'https://pay.hitechpay.vn/payment_link/m/pay_abc123'), 'payment link');
});

it('link ignores a method that is disabled or a different invoice id, and shows API errors', function () {
    $t = mock(['status' => 400, 'body' => ['error' => ['type' => 'invalid_request', 'message' => 'bad', 'code' => 'IR_06']]]);
    HiTechPayWhmcs::link(invoiceParams(['enableMomo' => '']), ['id' => '1001', 'hitechpay_method' => 'momo']);
    HiTechPayWhmcs::link(invoiceParams(), ['id' => '1002', 'hitechpay_method' => 'momo']);
    eq([], $t->calls);
    $html = HiTechPayWhmcs::link(invoiceParams(), ['id' => '1001', 'hitechpay_method' => 'momo']);
    check(str_contains($html, 'alert-danger'), 'error shown');
    eq('Create Payment Error', Whmcs::lastLogStatus());
});

it('production / custom base URL selection', function () {
    eq('https://api.hitechpay.vn', HiTechPayWhmcs::baseUrl(['sandbox' => '']));
    eq('https://sandbox-api.hitechpay.vn', HiTechPayWhmcs::baseUrl(['sandbox' => 'on']));
    eq('https://api.example.vn', HiTechPayWhmcs::baseUrl(['sandbox' => 'on', 'apiBaseUrl' => 'https://api.example.vn/']));
});

// ---------------------------------------------------------------- refund

it('refund posts /refunds and maps the result', function () {
    $t = mock(['status' => 200, 'body' => ['refund_id' => 'ref_1', 'payment_id' => 'pay_abc123', 'status' => 'succeeded']]);
    $r = hitechpay_refund(gateway() + ['transid' => 'pay_abc123', 'amount' => '50000.00', 'currency' => 'VND', 'invoiceid' => 1001]);
    eq('success', $r['status']);
    eq('ref_1', $r['transid']);
    eq('https://sandbox-api.hitechpay.vn/refunds', $t->calls[0]['url']);
    eq(['payment_id' => 'pay_abc123', 'amount' => 50000], array_intersect_key($t->calls[0]['body'], ['payment_id' => 1, 'amount' => 1]));

    mock(['status' => 200, 'body' => ['refund_id' => 'ref_2', 'status' => 'pending']]);
    eq('success', hitechpay_refund(gateway() + ['transid' => 'pay_abc123', 'amount' => '1', 'currency' => 'VND'])['status']);
    mock(['status' => 200, 'body' => ['refund_id' => 'ref_3', 'status' => 'failed']]);
    eq('declined', hitechpay_refund(gateway() + ['transid' => 'pay_abc123', 'amount' => '1', 'currency' => 'VND'])['status']);
    mock(['status' => 400, 'body' => ['error' => ['type' => 'invalid_request', 'message' => 'Refund amount exceeds', 'code' => 'IR_13']]]);
    $r = hitechpay_refund(gateway() + ['transid' => 'pay_abc123', 'amount' => '999999', 'currency' => 'VND']);
    eq('error', $r['status']);
    eq('IR_13', $r['rawdata']['code']);
});

echo "\n{$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed\n";
exit($GLOBALS['fail'] > 0 ? 1 : 0);
