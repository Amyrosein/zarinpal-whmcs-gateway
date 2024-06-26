<?php

/**
 * ZarinPal online gateway for whmcs 
 * @website		ZarinPal.com
 * @copyright	(c) 2023 - ZarinPal
 * @author	a.taghizade@zarinpal.com
 * Github https://github.com/Amyrosein
 */

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$zarinpal_urls = [
    'request_url' => [
        'sandbox'    => 'https://sandbox.zarinpal.com/pg/v4/payment/request.json',
        'production' => 'https://api.zarinpal.com/pg/v4/payment/request.json',
    ],

    'verify_url' => [
        'sandbox'    => 'https://sandbox.zarinpal.com/pg/v4/payment/verify.json',
        'production' => 'https://api.zarinpal.com/pg/v4/payment/verify.json',
    ],

    'redirect_url' => [
        'sandbox'    => 'https://sandbox.zarinpal.com/pg/StartPay/',
        'production' => 'https://www.zarinpal.com/pg/StartPay/',
    ],
];

$gatewayParams = getGatewayVariables('zarinpal');

if (!isset($_REQUEST['uuid'])) {
    header("Location: " . $gatewayParams['systemurl']);
    die;
}


if (!isset($_REQUEST['Authority'], $_GET['Status'])) {
    header("Location: " . $gatewayParams['systemurl']);
    die;
}


$invoiceId = checkCbInvoiceID($_REQUEST['uuid'], $gatewayParams['name']);
$invoice   = Capsule::table('tblinvoices')->where('id', $invoiceId)->where('status', 'Unpaid')->first();
$invoice_paid = Capsule::table('tblinvoices')->where('id', $invoiceId)->where('status', 'Paid')->first();

if ($invoice_paid) {
    header("Location: " . $gatewayParams['systemurl'] . 'viewinvoice.php?id=' . $invoice_paid->id);
    die;
}

if (!$invoice) {
    die("Invoice not found");
}


$result = zarinpal_req($zarinpal_urls['verify_url'][$gatewayParams['testMode'] == 'on' ? 'sandbox' : 'production'], [
    'merchant_id' => $gatewayParams['MerchantID'],
    'authority'   => $_GET['Authority'],
    'amount'      => ceil($invoice->total),
]);


if ($_GET['Status'] === 'OK') {
    if (is_numeric($result['data']['code']) && (int)$result['data']['code'] === 100) {
        checkCbTransID($result['data']['ref_id']);
        logTransaction($gatewayParams['name'], $_REQUEST, 'Success');
        addInvoicePayment(
            $invoice->id,
            $result['data']['ref_id'],
            (int)$invoice->total,
            0,
            $gatewayParams['paymentmethod']
        );
    } elseif (is_numeric($result['data']['code']) && (int)$result['data']['code'] === 101) {
        echo "Verified before";
        sleep(1);
    } else {
        logTransaction($gatewayParams['name'], array(
            'Code'        => 'Zarinpal Status Code',
            'Message'     => 'Code: ' . $result['errors']['code'] . ', Message: ' . $result['errors']['message'],
            'Transaction' => $_GET['Authority'],
            'Invoice'     => $invoiceId,
            'Amount'      => (int)$invoice->total,
        ), 'Failure');
    }
} else {
    header("Refresh: 3; URL=" . $gatewayParams['systemurl'] . 'viewinvoice.php?id=' . $invoice->id);
    echo '<h2 style="color: red; font-size: 40px; margin: 100px auto; width: 500px;" >پرداخت شما ناموفق بود</h2>';
}
header("Location: " . $gatewayParams['systemurl'] . 'viewinvoice.php?id=' . $invoice->id);
die;
