<?php

use WHMCS\Database\Capsule;

if (strtoupper($_SERVER['REQUEST_METHOD'] === 'GET')) {
    if (!defined("WHMCS")) {
        die("This file cannot be accessed directly");
    }
}

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

function zarinpal_req($url, array $parameters = [])
{
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => json_encode($parameters),
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'content-type: application/json',
        ],
    ]);

    $response = curl_exec($curl);

    if (curl_errno($curl) != 0) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new \Exception($error);
    }

    curl_close($curl);

    return json_decode($response, true);
}


function zarinpal_MetaData()
{
    return array(
        'DisplayName' => 'ماژول پرداخت آنلاین ZarinPal.com برای WHMCS',
        'APIVersion'  => '1.1',
    );
}

function zarinpal_config()
{
    return array(
        'FriendlyName' => array(
            'Type'  => 'System',
            'Value' => 'پرداخت امن زرین پال',
        ),
        'MerchantID'   => array(
            'FriendlyName' => 'مرچنت کد',
            'Type'         => 'text',
            'Size'         => '255',
            'Default'      => '',
            'Description'  => 'مرچنت کد خود را وارد کنید',
        ),
        'currencyType' => array(
            'FriendlyName' => 'واحد ارز',
            'Type'         => 'dropdown',
            'Options'      => array(
                'IRR' => 'ریال',
                'IRT' => 'تومان',
            ),
            'Description'  => 'واحد پولی را انتخاب کنید',
        ),
        // the yesno field type displays a single checkbox option
        'testMode'  => array(
            'FriendlyName' => 'حالت سندباکس',
            'Type'         => 'yesno',
            'Description'  => 'برای فعال کردن حالت سندباکس ( تستی ) تیک بزنید',
        ),
    );
}

function zarinpal_link($params)
{
    $invoiceId = $params['invoiceid'];
    $phone     = $params['clientdetails']['phonenumber'];

    // System Parameters
    $systemUrl  = $params['systemurl'];
    $returnUrl  = $params['returnurl'];
    $langPayNow = $params['langpaynow'];
    $moduleName = $params['paymentmethod'];

    $url = $systemUrl . 'modules/gateways/zarinpal.php';

    $postfields                 = array();
    $postfields['invoice_id']   = $invoiceId;
    $postfields['phone']        = $phone;
    $postfields['callback_url'] = $systemUrl . 'modules/gateways/callback/' . $moduleName . '.php';
    $postfields['return_url']   = $returnUrl;

    $htmlOutput = '<form method="post" action="' . $url . '">';
    foreach ($postfields as $k => $v) {
        $htmlOutput .= '<input type="hidden" name="' . $k . '" value="' . urlencode($v) . '" />';
    }
    $htmlOutput .= '<input type="submit" value="' . $langPayNow . '" />';
    $htmlOutput .= '</form>';

    return $htmlOutput;
}

if (
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST' && isset($_POST['invoice_id']) && is_numeric(
        $_POST['invoice_id']
    )
) {
    require_once __DIR__ . '/../../init.php';
    require_once __DIR__ . '/../../includes/gatewayfunctions.php';
    require_once __DIR__ . '/../../includes/invoicefunctions.php';

    if (isset($_SESSION['uid'])) {
        $gatewayParams = getGatewayVariables('zarinpal');

        //        echo "<pre>". print_r($gatewayParams, true) . "</pre>";
        //        die();

        $invoice = Capsule::table('tblinvoices')
            ->where('id', $_POST['invoice_id'])
            ->where('status', 'Unpaid')
            ->where('userid', $_SESSION['uid'])
            ->first();

        //        echo "<pre>". print_r($invoice, true) . "</pre>";
        //        die();
        if (!$invoice) {
            die("Invoice not found");
        }
        $client = Capsule::table('tblclients')->where('id', $_SESSION['uid'])->first();
        $amount = ceil($invoice->total * ($gatewayParams['currencyType'] == 'IRT' ? 10 : 1));
        //        echo "<pre>" . print_r(str_replace([' ', '+98.'], '', $client->phonenumber), true) . "</pre>";


        $data = [
            'merchant_id'  => $gatewayParams['MerchantID'],
            'amount'       => $amount,
            'description'  => sprintf('پرداخت فاکتور #%s', $invoice->id),
            'metadata'     => ['email' => $client->email, 'order_id' => strval($invoice->id)],
            'callback_url' => $gatewayParams['systemurl'] . 'modules/gateways/callback/zarinpal.php?uuid=' . $invoice->id,
        ];
        if (isset($client->phonenumber)) {
            $mobile                     = '0' . str_replace([' ', '+98.'], '', $client->phonenumber);
            $data['metadata']['mobile'] = $mobile;
        }

        try {
            $result = zarinpal_req(
                $zarinpal_urls['request_url'][$gatewayParams['testMode'] == 'on' ? 'sandbox' : 'production'],
                $data
            );
        } catch (Exception $e) {
            echo "<pre>" . print_r($e, true) . "</pre>";
            die;
        }

        //        echo "<pre>" . print_r($result, true) . "</pre>";
        //        echo "<pre>" . print_r($gatewayParams, true) . "</pre>";
        if (is_numeric($result['data']['code']) && (int)$result['data']['code'] === 100) {
            header(
                'Location: ' . $zarinpal_urls['redirect_url'][$gatewayParams['testMode'] == 'on' ? 'sandbox' : 'production'] . $result['data']['authority']
            );
            die;
        } else {
            $err_code = $result['errors']['code'];
            echo "عدم اتصال به درگاه کد خطا : $err_code";
        }
    }
}
