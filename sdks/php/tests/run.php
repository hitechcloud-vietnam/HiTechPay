<?php

/**
 * Dependency-free test runner: php tests/run.php
 */

declare(strict_types=1);

use HiTechPay\Exception\ApiErrorException;
use HiTechPay\Exception\AuthenticationException;
use HiTechPay\Exception\ConnectionException;
use HiTechPay\Exception\HiTechPayException;
use HiTechPay\Exception\InvalidRequestException;
use HiTechPay\Exception\NotFoundException;
use HiTechPay\Exception\RateLimitException;
use HiTechPay\Exception\SignatureVerificationException;
use HiTechPay\HiTechPayClient;
use HiTechPay\Http\Transport;
use HiTechPay\VietNam;
use HiTechPay\Webhook;

spl_autoload_register(static function (string $class): void {
    $prefix = 'HiTechPay\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// ---------------------------------------------------------------- mini harness
$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
$GLOBALS['__assertions'] = 0;

function check(bool $cond, string $msg = 'assertion failed'): void
{
    $GLOBALS['__assertions']++;
    if (!$cond) {
        throw new \AssertionError($msg);
    }
}

function eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    check($expected === $actual, ($msg ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

/** @param class-string<\Throwable> $class */
function throws(string $class, callable $fn, ?callable $inspect = null): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        check($e instanceof $class, "expected $class, got " . get_class($e) . ': ' . $e->getMessage());
        if ($inspect) {
            $inspect($e);
        }
        return;
    }
    check(false, "expected $class to be thrown");
}

function it(string $name, callable $fn): void
{
    try {
        $fn();
        $GLOBALS['__pass']++;
        echo "ok   - $name\n";
    } catch (\Throwable $e) {
        $GLOBALS['__fail']++;
        echo "FAIL - $name\n       " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

final class MockTransport implements Transport
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $calls = [];
    private int $i = 0;

    /** @param list<array{status:int,body?:mixed,headers?:array<string,string>}|\Throwable> $responses */
    public function __construct(private array $responses)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): array
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        $r = $this->responses[min($this->i++, count($this->responses) - 1)];
        if ($r instanceof \Throwable) {
            throw $r;
        }
        $b = $r['body'] ?? '';
        return [
            'status' => $r['status'],
            'headers' => $r['headers'] ?? [],
            'body' => is_string($b) ? $b : json_encode($b),
        ];
    }
}

function client(MockTransport $t, array $extra = []): HiTechPayClient
{
    return new HiTechPayClient($extra + [
        'api_key' => 'snd_secret',
        'base_url' => 'https://h',
        'transport' => $t,
        'retry_base_delay_ms' => 1,
        'retry_max_delay_ms' => 2,
    ]);
}

// ---------------------------------------------------------------- client tests
it('constructor validates api_key and defaults base_url', function () {
    throws(\InvalidArgumentException::class, fn () => new HiTechPayClient(['api_key' => '']));
    eq('https://api.hitechpay.vn', (new HiTechPayClient('k'))->getBaseUrl());
    eq('https://sandbox-api.hitechpay.vn', (new HiTechPayClient(['api_key' => 'k', 'base_url' => HiTechPayClient::SANDBOX_BASE_URL . '/']))->getBaseUrl());
});

it('payments->create sends api-key, JSON body, POST /payments', function () {
    $t = new MockTransport([['status' => 200, 'body' => ['payment_id' => 'pay_1', 'status' => 'requires_customer_action']]]);
    $res = client($t)->payments->create(['amount' => 150000, 'currency' => 'VND', 'confirm' => true, 'connector' => ['sepay']] + VietNam::vietQr());
    eq('pay_1', $res['payment_id']);
    eq(1, count($t->calls));
    $c = $t->calls[0];
    eq('POST', $c['method']);
    eq('https://h/payments', $c['url']);
    eq('snd_secret', $c['headers']['api-key']);
    eq('application/json', $c['headers']['Content-Type']);
    eq('application/json', $c['headers']['Accept']);
    check(str_starts_with($c['headers']['User-Agent'], 'hitechpay-php/'));
    eq('{"amount":150000,"currency":"VND","confirm":true,"connector":["sepay"],"payment_method":"real_time_payment","payment_method_type":"viet_qr","payment_method_data":{"real_time_payment":{"viet_qr":{}}}}', $c['body']);
});

it('resource paths and methods match Hyperswitch v1', function () {
    $t = new MockTransport([['status' => 200, 'body' => []]]);
    $c = client($t);
    $c->payments->retrieve('pay_1', ['force_sync' => true]);
    $c->payments->update('pay_1', ['description' => 'x']);
    $c->payments->confirm('pay_1');
    $c->payments->capture('pay_1', ['amount_to_capture' => 100]);
    $c->payments->cancel('pay_1');
    $c->payments->list(['limit' => 10, 'customer_id' => 'cus 1']);
    $c->refunds->create(['payment_id' => 'pay_1']);
    $c->refunds->retrieve('ref_1');
    $c->refunds->update('ref_1', ['reason' => 'r']);
    $c->refunds->list(['payment_id' => 'pay_1']);
    $c->customers->create(['name' => 'Nguyễn Văn A']);
    $c->customers->retrieve('cus_1');
    $c->customers->update('cus_1', ['email' => 'a@b.vn']);
    $c->customers->delete('cus_1');
    $c->customers->list(['limit' => 5]);
    $c->paymentMethods->list('cus_1');
    $c->paymentLinks->retrieve('plink_1');
    $c->mandates->retrieve('man_1');
    $c->mandates->revoke('man_1');
    $c->disputes->retrieve('dp_1');
    $c->disputes->list(['limit' => 3]);
    $c->payments->retrieve('a/b?c');
    $got = array_map(fn ($x) => $x['method'] . ' ' . substr($x['url'], strlen('https://h')), $t->calls);
    eq([
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
    ], $got);
    eq(null, $t->calls[0]['body']);
    check(!isset($t->calls[0]['headers']['Content-Type']), 'GET must not send Content-Type');
    eq('{}', $t->calls[2]['body'], 'empty body encodes as {}');
    eq('{"name":"Nguyễn Văn A"}', $t->calls[10]['body']);
    throws(\InvalidArgumentException::class, fn () => $c->payments->retrieve(''));
});

it('retries GET on 5xx / network / 429 then succeeds', function () {
    $t = new MockTransport([
        ['status' => 503, 'body' => ['error' => ['type' => 'api', 'message' => 'down']]],
        new ConnectionException('reset'),
        ['status' => 429, 'headers' => ['Retry-After' => '0']],
        ['status' => 200, 'body' => ['payment_id' => 'pay_1', 'status' => 'succeeded']],
    ]);
    $res = client($t, ['max_retries' => 3])->payments->retrieve('pay_1');
    eq('succeeded', $res['status']);
    eq(4, count($t->calls));
});

it('gives up after max_retries', function () {
    $t = new MockTransport([['status' => 500, 'body' => ['error' => ['type' => 'api', 'message' => 'boom', 'code' => 'HE_00']]]]);
    throws(ApiErrorException::class, fn () => client($t, ['max_retries' => 2])->customers->retrieve('c'), function (HiTechPayException $e) {
        eq(500, $e->getStatus());
        eq('HE_00', $e->getErrorCode());
    });
    eq(3, count($t->calls));
});

it('does not retry POST create; opt-in with idempotent', function () {
    $t = new MockTransport([['status' => 502, 'body' => 'Bad Gateway']]);
    throws(ApiErrorException::class, fn () => client($t, ['max_retries' => 3])->payments->create(['amount' => 1, 'currency' => 'VND']));
    eq(1, count($t->calls));

    $t2 = new MockTransport([['status' => 503], ['status' => 200, 'body' => ['payment_id' => 'order_42']]]);
    client($t2)->payments->create(['amount' => 1, 'currency' => 'VND', 'payment_id' => 'order_42'], ['idempotent' => true]);
    eq(2, count($t2->calls));
});

it('does not retry 4xx', function () {
    $t = new MockTransport([['status' => 404, 'body' => ['error' => ['type' => 'invalid_request', 'message' => 'Payment does not exist in our records', 'code' => 'HE_02']]]]);
    throws(NotFoundException::class, fn () => client($t, ['max_retries' => 3])->payments->retrieve('nope'));
    eq(1, count($t->calls));
});

it('maps error bodies to typed exceptions', function () {
    $body = ['error' => ['type' => 'invalid_request', 'message' => 'Missing required param: currency', 'code' => 'IR_04']];
    $t = new MockTransport([['status' => 400, 'body' => $body, 'headers' => ['X-Request-Id' => 'req_123']]]);
    throws(InvalidRequestException::class, fn () => client($t)->payments->create(['amount' => 1]), function (HiTechPayException $e) use ($body) {
        eq(400, $e->getStatus());
        eq('IR_04', $e->getErrorCode());
        eq('invalid_request', $e->getErrorType());
        eq('Missing required param: currency', $e->getMessage());
        eq($body, $e->getRaw());
        eq('req_123', $e->getRequestId());
    });
    throws(AuthenticationException::class, fn () => client(new MockTransport([['status' => 401, 'body' => ['error' => ['message' => 'API key not provided or invalid API key used', 'code' => 'IR_01']]]]))->payments->list());
    throws(RateLimitException::class, fn () => client(new MockTransport([['status' => 429, 'body' => 'slow down']]), ['max_retries' => 0])->payments->list(), function (HiTechPayException $e) {
        eq('slow down', $e->getMessage());
        eq('slow down', $e->getRaw());
    });
    throws(HiTechPayException::class, fn () => client(new MockTransport([['status' => 418]]), ['max_retries' => 0])->payments->list(), function (HiTechPayException $e) {
        eq(HiTechPayException::class, get_class($e));
        eq(418, $e->getStatus());
    });
});

it('network failure surfaces as ConnectionException', function () {
    $t = new MockTransport([new ConnectionException('reset', 0, 'network')]);
    throws(ConnectionException::class, fn () => client($t, ['max_retries' => 1])->payments->retrieve('p'));
    eq(2, count($t->calls));
});

it('CurlTransport reports connection errors', function () {
    if (!function_exists('curl_init')) {
        echo "       (skipped: ext-curl not loaded)\n";
        return;
    }
    $c = new HiTechPayClient(['api_key' => 'k', 'base_url' => 'http://127.0.0.1:1', 'max_retries' => 0, 'timeout_ms' => 2000]);
    throws(ConnectionException::class, fn () => $c->payments->retrieve('p'), fn (HiTechPayException $e) => eq(0, $e->getStatus()));
});

// ---------------------------------------------------------------- webhook tests
$key = 'pr_hash_key_test_123';
$body = json_encode([
    'merchant_id' => 'merchant_1',
    'event_id' => 'evt_1',
    'event_type' => 'payment_succeeded',
    'content' => ['type' => 'payment_details', 'object' => ['payment_id' => 'pay_1', 'status' => 'succeeded', 'amount' => 150000, 'currency' => 'VND']],
    'timestamp' => '2026-10-14T08:00:00.000Z',
]);
$sig512 = hash_hmac('sha512', $body, $key);
$sig256 = hash_hmac('sha256', $body, $key);

it('webhook verify accepts valid sha512 / sha256 / header arrays', function () use ($key, $body, $sig512, $sig256) {
    check(Webhook::verify($body, $sig512, $key));
    check(Webhook::verify($body, strtoupper($sig512), $key));
    check(Webhook::verify($body, $sig256, $key));
    check(Webhook::verify($body, ['X-Webhook-Signature-512' => $sig512], $key));
    check(Webhook::verify($body, ['x-webhook-signature-256' => [$sig256]], $key));
    check(Webhook::verify($body, ['HTTP_X_WEBHOOK_SIGNATURE_512' => $sig512], $key), '$_SERVER style');
});

it('webhook verify rejects invalid signatures', function () use ($key, $body, $sig512) {
    check(!Webhook::verify(str_replace('150000', '1', $body), $sig512, $key));
    check(!Webhook::verify($body, $sig512, 'other'));
    check(!Webhook::verify($body, 'abc', $key));
    check(!Webhook::verify($body, '', $key));
    check(!Webhook::verify($body, null, $key));
    check(!Webhook::verify($body, [], $key));
    check(!Webhook::verify($body, ['x-webhook-signature-256' => $sig512], $key));
    throws(\InvalidArgumentException::class, fn () => Webhook::verify($body, $sig512, ''));
});

it('webhook constructEvent verifies and decodes', function () use ($key, $body, $sig512) {
    $evt = Webhook::constructEvent($body, $sig512, $key);
    eq('payment_succeeded', $evt['event_type']);
    eq('pay_1', $evt['content']['object']['payment_id']);
    throws(SignatureVerificationException::class, fn () => Webhook::constructEvent($body, 'deadbeef', $key), fn ($e) => eq('signature_invalid', $e->getErrorCode()));
    throws(SignatureVerificationException::class, fn () => Webhook::constructEvent($body, null, $key), fn ($e) => eq('signature_missing', $e->getErrorCode()));
    throws(SignatureVerificationException::class, fn () => Webhook::constructEvent('nope', hash_hmac('sha512', 'nope', $key), $key), fn ($e) => eq('invalid_payload', $e->getErrorCode()));
});

// ---------------------------------------------------------------- VN tests
it('CRC16-CCITT-FALSE check value 0x29B1', function () {
    eq(0x29B1, VietNam::crc16('123456789'));
    eq(0xFFFF, VietNam::crc16(''));
});

it('formatVnd', function () {
    eq('150.000 ₫', VietNam::formatVnd(150000));
    eq('0 ₫', VietNam::formatVnd(0));
    eq('999 ₫', VietNam::formatVnd(999));
    eq('1.234.567.890 ₫', VietNam::formatVnd(1234567890));
    eq('-25.000 ₫', VietNam::formatVnd(-25000));
    eq('1.500 ₫', VietNam::formatVnd(1499.5));
    eq('50.000 ₫', VietNam::formatVnd('50000'));
    throws(\InvalidArgumentException::class, fn () => VietNam::formatVnd('abc'));
});

it('VN payment method presets encode correctly', function () {
    eq('{"payment_method":"wallet","payment_method_type":"momo","payment_method_data":{"wallet":{"momo_redirect":{}}}}', json_encode(VietNam::momo()));
    eq('{"payment_method":"card_redirect","payment_method_type":"momo_atm","payment_method_data":{"card_redirect":{"momo_atm":{}}}}', json_encode(VietNam::momoAtm()));
    eq('{"payment_method":"real_time_payment","payment_method_type":"viet_qr","payment_method_data":{"real_time_payment":{"viet_qr":{}}}}', json_encode(VietNam::vietQr()));
    eq('{"payment_method":"bank_redirect","payment_method_type":"local_bank_redirect","payment_method_data":{"bank_redirect":{"local_bank_redirect":{}}}}', json_encode(VietNam::localBankRedirect()));
    throws(\InvalidArgumentException::class, fn () => VietNam::paymentMethod('zalopay'));
});

it('buildVietQrPayload dynamic QR matches expected layout and CRC', function () {
    $p = VietNam::buildVietQrPayload('970422', '0123456789', 150000, 'Thanh toán ĐH 1001');
    $expectedBody = '000201' . '010212'
        . '3854' . '0010A000000727' . '0124' . '0006970422' . '01100123456789' . '0208QRIBFTTA'
        . '5303704' . '5406150000' . '5802VN' . '6222' . '0818Thanh toan DH 1001' . '6304';
    eq($expectedBody, substr($p, 0, -4));
    eq(sprintf('%04X', VietNam::crc16($expectedBody)), substr($p, -4));
    // Same payload as the Node SDK produces (cross-checked with Python binascii.crc_hqx)
    eq('00020101021238540010A00000072701240006970422011001234567890208QRIBFTTA530370454061500005802VN62100806DH10016304EE84',
        VietNam::buildVietQrPayload('970422', '0123456789', 150000, 'DH1001'));
    $tags = VietNam::parseTlv($p);
    eq('12', $tags['01']);
    eq(['00' => '970422', '01' => '0123456789'], VietNam::parseTlv(VietNam::parseTlv($tags['38'])['01']));
});

it('buildVietQrPayload static QR and validation', function () {
    $tags = VietNam::parseTlv(VietNam::buildVietQrPayload('970436', '1234567'));
    eq('11', $tags['01']);
    check(!isset($tags['54']) && !isset($tags['62']));
    throws(\InvalidArgumentException::class, fn () => VietNam::buildVietQrPayload('97042', '1'));
    throws(\InvalidArgumentException::class, fn () => VietNam::buildVietQrPayload('970422', '12-3'));
    throws(\InvalidArgumentException::class, fn () => VietNam::buildVietQrPayload('970422', '1', 0));
    throws(\LengthException::class, fn () => VietNam::buildVietQrPayload('970422', '1', 1000, str_repeat('x', 26)));
});

it('sanitizeMemo strips Vietnamese diacritics', function () {
    eq('Don hang so 42 Ha Noi', VietNam::sanitizeMemo('  Đơn hàng   số #42 – Hà Nội '));
    eq('DUONG', VietNam::sanitizeMemo('ĐƯỜNG'));
});

// ---------------------------------------------------------------- summary
$pass = $GLOBALS['__pass'];
$fail = $GLOBALS['__fail'];
echo "\n# tests " . ($pass + $fail) . "\n# pass {$pass}\n# fail {$fail}\n# assertions {$GLOBALS['__assertions']}\n";
exit($fail === 0 ? 0 : 1);
