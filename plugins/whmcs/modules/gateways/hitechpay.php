<?php

/**
 * HiTechPay payment gateway module for WHMCS.
 *
 * VietQR, MoMo, ZaloPay / VNPAY and cards through HiTechPay (Hyperswitch-compatible API).
 * Webhook / return handler: modules/gateways/callback/hitechpay.php
 *
 * Copyright (c) 2026 Pho Tue Software Solutions JSC & HiTechCloud Vietnam — Apache-2.0
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/hitechpay/HiTechPayWhmcs.php';

function hitechpay_MetaData()
{
    return [
        'DisplayName' => 'HiTechPay (VietQR, MoMo, ZaloPay, VNPAY, thẻ)',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

function hitechpay_config()
{
    return HiTechPayWhmcs::configFields();
}

/**
 * Invoice page: payment method buttons; after a choice, creates the payment / payment link
 * and redirects (MoMo, ZaloPay / VNPAY, cards) or shows the VietQR code.
 */
function hitechpay_link($params)
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return HiTechPayWhmcs::link($params, $_GET, $_SESSION);
    }
    $noSession = null;
    return HiTechPayWhmcs::link($params, $_GET, $noSession);
}

/** Refund through POST /refunds. $params['transid'] is the HiTechPay payment_id. */
function hitechpay_refund($params)
{
    return HiTechPayWhmcs::refund($params);
}
