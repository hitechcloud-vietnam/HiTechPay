<?php

/**
 * HiTechPay callback for WHMCS.
 *
 *  - POST (webhook from HiTechPay): verifies X-Webhook-Signature-512 = hex HMAC-SHA512(raw body,
 *    Payment Response Hash Key) and records succeeded payments on the invoice.
 *  - GET ?return=1&invoiceid=..&payment_id=.. (customer returning / "I have paid"): re-reads the
 *    payment from the API with the secret key and records it, then redirects to the invoice.
 *
 * Webhook URL to configure in the HiTechPay Dashboard:
 *   https://<your-whmcs>/modules/gateways/callback/hitechpay.php
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../hitechpay/HiTechPayWhmcs.php';

$gatewayModuleName = basename(__FILE__, '.php');
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (empty($gatewayParams['type'])) {
    die('Module Not Activated');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = HiTechPayWhmcs::handleWebhook(
        $gatewayParams,
        (string) file_get_contents('php://input'),
        HiTechPayWhmcs::signatureFromServer($_SERVER)
    );
    http_response_code($result['status']);
    header('Content-Type: application/json');
    echo $result['body'];
    exit;
}

if (!empty($_GET['return'])) {
    header('Location: ' . HiTechPayWhmcs::handleReturn($gatewayParams, $_GET), true, 303);
    exit;
}

http_response_code(405);
echo 'Method Not Allowed';
