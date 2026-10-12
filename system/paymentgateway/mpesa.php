<?php

function mpesa_config($key, $default = '')
{
    $setting = ORM::for_table('tbl_appconfig')->where('setting', 'mpesa_' . $key)->find_one();
    return $setting ? $setting['value'] : $default;
}

function mpesa_save_setting($key, $value)
{
    $setting = ORM::for_table('tbl_appconfig')->where('setting', 'mpesa_' . $key)->find_one();
    if (!$setting) {
        $setting = ORM::for_table('tbl_appconfig')->create();
        $setting->setting = 'mpesa_' . $key;
    }
    $setting->value = $value;
    $setting->save();
}

function mpesa_show_config()
{
    global $ui;
    $config = [
        'environment' => mpesa_config('environment', 'sandbox'),
        'shortcode' => mpesa_config('shortcode'),
        'country_code' => mpesa_config('country_code', '254'),
        'callback_url' => mpesa_config('callback_url'),
        'callback_default' => APP_URL . '/?_route=callback/mpesa',
        'transaction_type' => mpesa_config('transaction_type', 'CustomerPayBillOnline'),
        'consumer_key_set' => mpesa_config('consumer_key') !== '',
        'consumer_secret_set' => mpesa_config('consumer_secret') !== '',
        'passkey_set' => mpesa_config('passkey') !== '',
    ];
    $ui->assign('mpesa', $config);
    $ui->assign('csrf_token', Csrf::generateAndStoreToken());
    $ui->assign('_title', 'M-Pesa STK Push Settings');
    $ui->display('admin/paymentgateway/mpesa.tpl');
}

function mpesa_save_config()
{
    if (!Csrf::check(_post('csrf_token'))) {
        r2(getUrl('paymentgateway/mpesa'), 'e', Lang::T('Invalid or Expired CSRF Token'));
    }

    $environment = _post('environment');
    $transactionType = _post('transaction_type');
    if (!in_array($environment, ['sandbox', 'production'], true)) {
        r2(getUrl('paymentgateway/mpesa'), 'e', 'Choose a valid M-Pesa environment.');
    }
    if (!in_array($transactionType, ['CustomerPayBillOnline', 'CustomerBuyGoodsOnline'], true)) {
        r2(getUrl('paymentgateway/mpesa'), 'e', 'Choose a valid M-Pesa transaction type.');
    }

    $callbackUrl = trim(_post('callback_url'));
    if ($callbackUrl !== '' && !filter_var($callbackUrl, FILTER_VALIDATE_URL)) {
        r2(getUrl('paymentgateway/mpesa'), 'e', 'Enter a valid callback URL or leave it blank.');
    }

    mpesa_save_setting('environment', $environment);
    mpesa_save_setting('shortcode', trim(_post('shortcode')));
    mpesa_save_setting('country_code', preg_replace('/\D+/', '', _post('country_code')));
    mpesa_save_setting('callback_url', $callbackUrl);
    mpesa_save_setting('transaction_type', $transactionType);

    foreach (['consumer_key', 'consumer_secret', 'passkey'] as $secret) {
        $value = trim(_post($secret));
        if ($value !== '') {
            mpesa_save_setting($secret, $value);
        }
    }

    r2(getUrl('paymentgateway/mpesa'), 's', 'M-Pesa settings saved.');
}

function mpesa_validate_config()
{
    $required = ['consumer_key', 'consumer_secret', 'shortcode', 'passkey'];
    foreach ($required as $key) {
        if (mpesa_config($key) === '') {
            r2(getUrl('paymentgateway/mpesa'), 'e', 'Complete the M-Pesa settings before accepting payments.');
        }
    }
}

function mpesa_api_base_url()
{
    return mpesa_config('environment', 'sandbox') === 'production'
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke';
}

function mpesa_http_request($url, $method = 'GET', $headers = [], $body = null)
{
    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        return ['status' => $status, 'body' => $responseBody, 'error' => $error];
    }

    if (!extension_loaded('openssl')) {
        return ['status' => 0, 'body' => false, 'error' => 'Enable the PHP cURL and OpenSSL extensions to connect to Safaricom Daraja.'];
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body === null ? '' : $body,
            'timeout' => 25,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $responseBody = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
            $status = (int) $matches[1];
        }
    }
    return ['status' => $status, 'body' => $responseBody, 'error' => $responseBody === false ? 'Could not connect to Safaricom Daraja.' : ''];
}

function mpesa_response_json($response)
{
    if ($response['body'] === false || $response['status'] < 200 || $response['status'] >= 300) {
        $detail = $response['error'] ?: (string) $response['body'];
        throw new RuntimeException('Safaricom request failed: ' . $detail);
    }
    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Safaricom returned an invalid response.');
    }
    if (!empty($decoded['errorMessage'])) {
        throw new RuntimeException($decoded['errorMessage']);
    }
    return $decoded;
}

function mpesa_access_token()
{
    $key = mpesa_config('consumer_key');
    $secret = mpesa_config('consumer_secret');
    $response = mpesa_http_request(
        mpesa_api_base_url() . '/oauth/v1/generate?grant_type=client_credentials',
        'GET',
        ['Authorization' => 'Basic ' . base64_encode($key . ':' . $secret)]
    );
    $data = mpesa_response_json($response);
    if (empty($data['access_token'])) {
        throw new RuntimeException('Safaricom did not return an access token.');
    }
    return $data['access_token'];
}

function mpesa_timestamp()
{
    $timezone = new DateTimeZone('Africa/Nairobi');
    return (new DateTimeImmutable('now', $timezone))->format('YmdHis');
}

function mpesa_password($timestamp)
{
    return base64_encode(mpesa_config('shortcode') . mpesa_config('passkey') . $timestamp);
}

function mpesa_normalize_phone($phone)
{
    $phone = preg_replace('/\D+/', '', (string) $phone);
    $countryCode = mpesa_config('country_code', '254');
    if (strpos($phone, '00') === 0) {
        $phone = substr($phone, 2);
    }
    if (strpos($phone, '0') === 0) {
        $phone = $countryCode . substr($phone, 1);
    } elseif (strlen($phone) === 9) {
        $phone = $countryCode . $phone;
    }
    return preg_match('/^\d{12}$/', $phone) ? $phone : false;
}

function mpesa_create_transaction($transaction, $user)
{
    global $config;
    mpesa_validate_config();

    $amount = (float) $transaction['price'];
    if ($amount < 1 || abs($amount - round($amount)) > 0.00001) {
        r2(getUrl('order/view/') . $transaction['id'], 'e', 'M-Pesa requires a whole-number amount of at least KES 1. Set the bundle price in whole KES.');
    }
    $phone = mpesa_normalize_phone($user['phonenumber']);
    if (!$phone) {
        r2(getUrl('accounts/profile'), 'e', 'Add a valid mobile number to your customer profile before paying with M-Pesa.');
    }

    try {
        $token = mpesa_access_token();
        $timestamp = mpesa_timestamp();
        $callbackUrl = mpesa_config('callback_url');
        if ($callbackUrl === '') {
            $callbackUrl = APP_URL . '/?_route=callback/mpesa';
        }
        $payload = [
            'BusinessShortCode' => mpesa_config('shortcode'),
            'Password' => mpesa_password($timestamp),
            'Timestamp' => $timestamp,
            'TransactionType' => mpesa_config('transaction_type', 'CustomerPayBillOnline'),
            'Amount' => (int) round($amount),
            'PartyA' => $phone,
            'PartyB' => mpesa_config('shortcode'),
            'PhoneNumber' => $phone,
            'CallBackURL' => $callbackUrl,
            'AccountReference' => substr('NB' . $transaction['id'], 0, 12),
            'TransactionDesc' => substr('Bundle ' . $transaction['plan_name'], 0, 13),
        ];
        $response = mpesa_response_json(mpesa_http_request(
            mpesa_api_base_url() . '/mpesa/stkpush/v1/processrequest',
            'POST',
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            json_encode($payload)
        ));
        if (($response['ResponseCode'] ?? '') !== '0' || empty($response['CheckoutRequestID'])) {
            throw new RuntimeException($response['CustomerMessage'] ?? $response['ResponseDescription'] ?? 'Safaricom did not accept the STK Push request.');
        }

        $transaction->gateway_trx_id = $response['CheckoutRequestID'];
        $transaction->payment_method = 'M-Pesa';
        $transaction->payment_channel = 'STK Push';
        $transaction->pg_url_payment = APP_URL . '/?_route=order/view/' . $transaction['id'];
        $transaction->pg_request = json_encode(['phone' => $phone, 'amount' => (int) round($amount), 'response' => $response]);
        $transaction->save();
        r2(getUrl('order/view/') . $transaction['id'], 's', 'M-Pesa payment prompt sent. Check your phone and enter your M-Pesa PIN.');
    } catch (Throwable $error) {
        $transaction->pg_request = json_encode(['error' => $error->getMessage()]);
        $transaction->save();
        r2(getUrl('order/view/') . $transaction['id'], 'e', 'M-Pesa could not start the payment: ' . $error->getMessage());
    }
}

function mpesa_callback()
{
    $input = file_get_contents('php://input');
    $payload = json_decode($input, true);
    $callback = $payload['Body']['stkCallback'] ?? null;
    if (!$callback || empty($callback['CheckoutRequestID'])) {
        http_response_code(400);
        echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid callback']);
        return;
    }

    $transaction = ORM::for_table('tbl_payment_gateway')
        ->where('gateway', 'mpesa')
        ->where('gateway_trx_id', $callback['CheckoutRequestID'])
        ->find_one();
    if (!$transaction) {
        http_response_code(404);
        echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Transaction not found']);
        return;
    }
    if ((int) $transaction['status'] === 1) {
        try {
            $verified = mpesa_query_transaction($transaction);
            if ((string) ($verified['ResultCode'] ?? '') === '0') {
                mpesa_process_result($transaction, $verified, $payload);
            } elseif (isset($verified['ResultCode'])) {
                mpesa_process_result($transaction, $verified, $verified);
            }
        } catch (Throwable $error) {
            _log('M-Pesa callback verification failed for transaction ' . $transaction['id'] . ': ' . $error->getMessage());
            http_response_code(503);
            echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Payment verification is temporarily unavailable']);
            return;
        }
    }
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
}

function mpesa_callback_metadata($callback)
{
    $metadata = [];
    foreach ($callback['CallbackMetadata']['Item'] ?? [] as $item) {
        if (isset($item['Name'])) {
            $metadata[$item['Name']] = $item['Value'] ?? null;
        }
    }
    return $metadata;
}

function mpesa_process_result($transaction, $result, $rawResponse)
{
    if ((string) ($result['ResultCode'] ?? '') !== '0') {
        $transaction->pg_paid_response = json_encode($rawResponse);
        $transaction->payment_channel = 'STK Push failed';
        $transaction->status = 4;
        $transaction->paid_date = date('Y-m-d H:i:s');
        $transaction->save();
        return false;
    }

    $metadata = mpesa_callback_metadata($result);
    $expectedAmount = (int) round((float) $transaction['price']);
    if (isset($metadata['Amount']) && (int) $metadata['Amount'] !== $expectedAmount) {
        return false;
    }

    $customer = ORM::for_table('tbl_customers')->find_one($transaction['user_id']);
    if (!$customer || (int) $transaction['plan_id'] < 1) {
        return false;
    }

    global $trx;
    $trx = $transaction;
    try {
        $invoice = Package::rechargeUser(
            $customer['id'],
            $transaction['routers'],
            $transaction['plan_id'],
            'M-Pesa',
            'STK Push',
            !empty($metadata['MpesaReceiptNumber']) ? 'M-Pesa receipt: ' . $metadata['MpesaReceiptNumber'] : ''
        );
    } catch (Throwable $error) {
        _log('M-Pesa bundle activation failed for transaction ' . $transaction['id'] . ': ' . $error->getMessage());
        return false;
    }
    if (empty($invoice)) {
        return false;
    }

    $transaction->trx_invoice = $invoice;
    $transaction->payment_method = 'M-Pesa';
    $transaction->payment_channel = !empty($metadata['MpesaReceiptNumber'])
        ? 'STK Push (' . $metadata['MpesaReceiptNumber'] . ')'
        : 'STK Push';
    $transaction->pg_paid_response = json_encode($rawResponse);
    $transaction->status = 2;
    $transaction->paid_date = date('Y-m-d H:i:s');
    $transaction->save();
    return true;
}

function mpesa_query_transaction($transaction)
{
    mpesa_validate_config();
    if (empty($transaction['gateway_trx_id'])) {
        throw new RuntimeException('M-Pesa has not returned a checkout reference for this payment yet.');
    }
    $token = mpesa_access_token();
    $timestamp = mpesa_timestamp();
    $payload = [
        'BusinessShortCode' => mpesa_config('shortcode'),
        'Password' => mpesa_password($timestamp),
        'Timestamp' => $timestamp,
        'CheckoutRequestID' => $transaction['gateway_trx_id'],
    ];
    return mpesa_response_json(mpesa_http_request(
        mpesa_api_base_url() . '/mpesa/stkpushquery/v1/query',
        'POST',
        ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
        json_encode($payload)
    ));
}

function mpesa_get_status($transaction, $user)
{
    try {
        $response = mpesa_query_transaction($transaction);
        if (($response['ResultCode'] ?? null) === '0' || ($response['ResultCode'] ?? null) === 0) {
            mpesa_process_result($transaction, $response, $response);
            r2(getUrl('order/view/') . $transaction['id'], 's', 'M-Pesa payment confirmed. Your bundle has been activated.');
        }
        if (isset($response['ResultCode']) && (string) $response['ResultCode'] !== '') {
            $transaction->pg_paid_response = json_encode($response);
            $transaction->payment_channel = 'STK Push failed';
            $transaction->status = 4;
            $transaction->paid_date = date('Y-m-d H:i:s');
            $transaction->save();
            r2(getUrl('order/view/') . $transaction['id'], 'e', $response['ResultDesc'] ?? 'M-Pesa payment was not completed.');
        }
        r2(getUrl('order/view/') . $transaction['id'], 'w', $response['ResponseDescription'] ?? 'M-Pesa payment is still pending. Approve the prompt on your phone, then check again.');
    } catch (Throwable $error) {
        r2(getUrl('order/view/') . $transaction['id'], 'e', 'Could not check M-Pesa payment: ' . $error->getMessage());
    }
}

function mpesa_payment_notification()
{
    mpesa_callback();
}