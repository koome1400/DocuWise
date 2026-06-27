<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;

class Billing extends ResourceController
{
    protected $format = 'json';

    public function getHistory($userId = null)
    {

        $uid = $userId ?? 1;
        $db = \Config\Database::connect();
        $history = $db->table('transactions')
            ->where('user_id', $uid)
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getResult();

        return $this->response->setJSON($history);
    }

    public function initiatePayment()
    {
        // Log EVERYTHING for debugging
        log_message('debug', 'Payment initiation called');

        $json = $this->request->getJSON();

        // Log what was received
        log_message('debug', 'Request data: ' . json_encode($json));

        if (!$json) {
            log_message('error', 'No JSON data received in payment request');
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'No data received. Make sure Content-Type is application/json'
            ]);
        }

        $gateway = $json->gateway ?? null;
        $amount = (float) ($json->amount ?? 5.00);
        $userId = $json->user_id ?? 1;

        // Pricing Logic
        $multiplier = 100;
        $tokensToBuy = $amount * $multiplier;

        if ($gateway === 'stripe') {
            return $this->handleStripe($amount, $tokensToBuy, $userId);
        }

        if ($gateway === 'mpesa') {
            $phone = $json->phone ?? '';
            return $this->handleMpesa($amount, $tokensToBuy, $userId, $phone);
        }

        return $this->response->setStatusCode(400)->setJSON([
            'status' => 'error',
            'message' => 'Invalid gateway. Use "stripe" or "mpesa"'
        ]);
    }

    private function handleStripe($amount, $tokens, $userId)
    {
        // Check if Stripe key exists
        $stripeKey = 'sk_test_51TKECuAivi8vOtoBVKtqEbxjrm7upsiXEwZZo3yP1yCSArRxthtLcikmQ75fHl9oyEAHn2Fwp6soTxdDSOKDgI2G00HQCx2sVG';

        if (empty($stripeKey)) {
            log_message('error', 'Stripe secret key is missing');
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Stripe configuration error'
            ]);
        }

        \Stripe\Stripe::setApiKey($stripeKey);
        \Stripe\ApiRequestor::setHttpClient(new \Stripe\HttpClient\CurlClient([CURLOPT_SSL_VERIFYPEER => false]));

        try {
            $intent = \Stripe\PaymentIntent::create([
                'amount' => (int) ($amount * 100),
                'currency' => 'usd',
                'metadata' => [
                    'user_id' => $userId,
                    'tokens' => $tokens
                ]
            ]);

            log_message('debug', 'PaymentIntent created: ' . $intent->id);

            // Return EXACTLY what the SDK expects
            return $this->response->setJSON([
                'status' => 'success',
                'client_secret' => $intent->client_secret,
                'payment_intent_id' => $intent->id
            ]);

        } catch (\Exception $e) {
            log_message('error', 'Stripe Error: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    private function handleMpesa($amount, $tokens, $userId, $phone)
    {
        // Clean phone number
        $phone = preg_replace('/\s+/', '', $phone);
        if (substr($phone, 0, 1) === '0') {
            $phone = '254' . substr($phone, 1);
        }
        if (substr($phone, 0, 4) === '+254') {
            $phone = substr($phone, 1);
        }

        // Get credentials from .env
        $consumerKey = getenv('MPESA_CONSUMER_KEY') ?: 'otCAlsPZWtY4MXGklxvP53MUpEhckadT0zjFA1D8IO2bbRGY';
        $consumerSecret = getenv('MPESA_CONSUMER_SECRET') ?: 'PKGx5mnArPKkliC0mlfGkMC6sS4bVMjaLWpkAdHAJZfCwcyLZyQ1XNOf9YuQYy5d';
        $BusinessShortCode = getenv('MPESA_SHORTCODE') ?: '174379';
        $Passkey = getenv('MPESA_PASSKEY') ?: 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919';
        $callbackUrl = getenv('MPESA_CALLBACK_URL') ?: 'https://uniconoclastically-cattleless-myong.ngrok-free.dev/mpesa-callback';

        // --- STEP 1: GET ACCESS TOKEN ---
        $url = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . base64_encode($consumerKey . ':' . $consumerSecret),
            'Content-Type: application/json; charset=utf-8'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        log_message('debug', 'M-Pesa Auth Response Code: ' . $httpCode);
        log_message('debug', 'M-Pesa Auth Response: ' . $response);

        if ($httpCode !== 200) {
            return $this->response->setJSON([
                'ResponseCode' => '1',
                'CustomerMessage' => 'Payment service unavailable. Please try again.'
            ]);
        }

        $result = json_decode($response);
        if (!isset($result->access_token)) {
            log_message('error', 'M-Pesa Token missing: ' . $response);
            return $this->response->setJSON([
                'ResponseCode' => '1',
                'CustomerMessage' => 'Authentication failed.'
            ]);
        }

        $accessToken = $result->access_token;

        // --- STEP 2: STK PUSH ---
        $Timestamp = date('YmdHis');
        $Password = base64_encode($BusinessShortCode . $Passkey . $Timestamp);

        $stkUrl = 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';

        $stkData = [
            'BusinessShortCode' => $BusinessShortCode,
            'Password' => $Password,
            'Timestamp' => $Timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => (int) $amount,
            'PartyA' => (int) $phone,
            'PartyB' => (int) $BusinessShortCode,
            'PhoneNumber' => (int) $phone,
            'CallBackURL' => $callbackUrl,
            'AccountReference' => 'DocuWisePay',
            'TransactionDesc' => 'Token Topup'
        ];

        log_message('debug', 'M-Pesa STK Request: ' . json_encode($stkData));

        $ch = curl_init($stkUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($stkData));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $stkResponse = curl_exec($ch);
        $stkHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        log_message('debug', 'M-Pesa STK Response: ' . $stkResponse);

        $responseData = json_decode($stkResponse, true);

        // Return response in format SDK expects
        if ($responseData && isset($responseData['ResponseCode']) && $responseData['ResponseCode'] == '0') {
            // Save pending transaction
            $db = \Config\Database::connect();
            $db->table('transactions')->insert([
                'user_id' => $userId,
                'type' => 'pending',
                'amount_usd' => 0,
                'tokens' => $tokens,
                'description' => "M-Pesa payment of KES $amount",
                'gateway' => 'mpesa',
                'gateway_ref' => $responseData['CheckoutRequestID'],
                'created_at' => date('Y-m-d H:i:s')
            ]);

            return $this->response->setJSON([
                'ResponseCode' => '0',
                'CustomerMessage' => 'STK Push sent. Check your phone.',
                'CheckoutRequestID' => $responseData['CheckoutRequestID']
            ]);
        } else {
            $errorMsg = $responseData['errorMessage'] ?? 'STK Push failed. Please try again.';
            return $this->response->setJSON([
                'ResponseCode' => '1',
                'CustomerMessage' => $errorMsg
            ]);
        }
    }

    public function mpesaCallback()
    {
        log_message('debug', '========== MPESA CALLBACK HIT ==========');

        $callbackData = file_get_contents('php://input');
        log_message('debug', 'Raw Callback Data: ' . $callbackData);

        $data = json_decode($callbackData, true);

        if (isset($data['Body']['stkCallback']['ResultCode'])) {
            $resultCode = $data['Body']['stkCallback']['ResultCode'];
            $resultDesc = $data['Body']['stkCallback']['ResultDesc'] ?? '';
            $checkoutId = $data['Body']['stkCallback']['CheckoutRequestID'] ?? '';
            $amount = $data['Body']['stkCallback']['Amount'] ?? 5;

            if ($resultCode == 0) {
                // Payment successful - calculate tokens (1 KES = 100 tokens)
                $tokens = $amount * 100;

                $userModel = new \App\Models\UserModel();
                $user = $userModel->find(1);

                if ($user) {
                    $newBalance = ($user['token_balance'] ?? 0) + $tokens;
                    $userModel->update(1, ['token_balance' => $newBalance]);

                    // Update pending transaction to completed
                    $db = \Config\Database::connect();
                    $db->table('transactions')
                        ->where('gateway_ref', $checkoutId)
                        ->update([
                            'type' => 'credit',
                            'amount_usd' => $amount / 100, // Convert cents to dollars
                            'status' => 'completed'
                        ]);

                    log_message('debug', "✅ M-Pesa Success! Added $tokens tokens. New balance: $newBalance");
                }
            } else {
                log_message('error', "❌ M-Pesa Failed: $resultDesc (Code: $resultCode)");
            }
        }

        return $this->response->setJSON(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    public function mpesaStatus()
    {
        $checkoutId = $this->request->getGet('checkoutId');

        if (!$checkoutId) {
            return $this->response->setJSON(['status' => 'unknown']);
        }

        $db = \Config\Database::connect();

        // Check if transaction was completed
        $transaction = $db->table('transactions')
            ->where('gateway_ref', $checkoutId)
            ->where('type', 'credit')
            ->get()
            ->getRow();

        if ($transaction) {
            return $this->response->setJSON([
                'status' => 'completed',
                'tokens' => $transaction->tokens
            ]);
        }

        // Check if transaction is pending
        $pending = $db->table('transactions')
            ->where('gateway_ref', $checkoutId)
            ->where('type', 'pending')
            ->get()
            ->getRow();

        if ($pending) {
            return $this->response->setJSON(['status' => 'pending']);
        }

        return $this->response->setJSON(['status' => 'unknown']);
    }

    public function simulateMpesaCallback()
    {
        $checkoutId = $this->request->getGet('checkoutId');
        $amount = $this->request->getGet('amount') ?? 5;

        if (!$checkoutId) {
            return $this->response->setJSON(['error' => 'Missing checkoutId']);
        }

        // Manually add tokens
        $tokens = $amount * 100;
        $userModel = new \App\Models\UserModel();
        $user = $userModel->find(1);

        if ($user) {
            $newBalance = ($user['token_balance'] ?? 0) + $tokens;
            $userModel->update(1, ['token_balance' => $newBalance]);

            $db = \Config\Database::connect();
            $db->table('transactions')->insert([
                'user_id' => 1,
                'type' => 'credit',
                'amount_usd' => 0,
                'tokens' => $tokens,
                'description' => "M-Pesa Payment of KES $amount",
                'gateway' => 'mpesa',
                'gateway_ref' => $checkoutId,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            return $this->response->setJSON([
                'status' => 'success',
                'message' => 'Payment completed manually',
                'new_balance' => $newBalance
            ]);
        }

        return $this->response->setJSON(['status' => 'error', 'message' => 'User not found']);
    }

    public function createPayPalOrder()
    {
        $amount = $this->request->getJSON()->amount ?? 5;
        $userId = $this->request->getJSON()->user_id ?? 1;
        $tokens = $amount * 100;

        $clientId = getenv('PAYPAL_CLIENT_ID');
        $clientSecret = getenv('PAYPAL_SECRET');
        $mode = getenv('PAYPAL_MODE') ?: 'sandbox';
        $apiUrl = $mode === 'sandbox'
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';

        // Get access token
        $ch = curl_init($apiUrl . '/v1/oauth2/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERPWD, "$clientId:$clientSecret");
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $tokenData = json_decode($response, true);

        if (!isset($tokenData['access_token'])) {
            log_message('error', 'PayPal Auth Failed: ' . json_encode($tokenData));
            return $this->response->setJSON(['status' => 'error', 'message' => 'PayPal authentication failed']);
        }

        $accessToken = $tokenData['access_token'];

        // Create order
        $orderData = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'amount' => [
                        'currency_code' => 'USD',
                        'value' => number_format($amount, 2, '.', '')
                    ],
                    'custom_id' => (string) $userId,
                    'description' => "DocuWise Token Topup - $tokens tokens"
                ]
            ],
            'application_context' => [
                'return_url' => base_url('/paypal-success'),
                'cancel_url' => base_url('/paypal-cancel'),
                'brand_name' => 'DocuWise',
                'user_action' => 'PAY_NOW'
            ]
        ];

        $ch = curl_init($apiUrl . '/v2/checkout/orders');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($orderData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $order = json_decode($response, true);

        // Extract approval URL
        $approvalUrl = null;
        foreach ($order['links'] ?? [] as $link) {
            if ($link['rel'] === 'approve') {
                $approvalUrl = $link['href'];
                break;
            }
        }

        if (!$approvalUrl) {
            log_message('error', 'PayPal Order Error: ' . json_encode($order));
            return $this->response->setJSON(['status' => 'error', 'message' => 'Failed to create PayPal order']);
        }

        // Save pending transaction
        $db = \Config\Database::connect();
        $db->table('transactions')->insert([
            'user_id' => $userId,
            'type' => 'pending',
            'status' => 'pending',
            'amount_usd' => $amount,
            'tokens' => $tokens,
            'description' => "PayPal payment initiated - Order ID: {$order['id']}",
            'gateway' => 'paypal',
            'gateway_ref' => $order['id'],
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return $this->response->setJSON([
            'status' => 'success',
            'approval_url' => $approvalUrl,
            'order_id' => $order['id']
        ]);
    }

    public function paypalSuccess()
    {
        $orderId = $this->request->getGet('token');
        $payerId = $this->request->getGet('PayerID');

        if (!$orderId || !$payerId) {
            return redirect()->to('/')->with('error', 'Invalid PayPal callback');
        }

        $clientId = getenv('PAYPAL_CLIENT_ID');
        $clientSecret = getenv('PAYPAL_SECRET');
        $mode = getenv('PAYPAL_MODE') ?: 'sandbox';
        $apiUrl = $mode === 'sandbox'
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';

        // Get access token
        $ch = curl_init($apiUrl . '/v1/oauth2/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERPWD, "$clientId:$clientSecret");
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $tokenData = json_decode($response, true);
        $accessToken = $tokenData['access_token'] ?? null;

        if (!$accessToken) {
            return redirect()->to('/')->with('error', 'PayPal authentication failed');
        }

        // Capture the order
        $ch = curl_init($apiUrl . '/v2/checkout/orders/' . $orderId . '/capture');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $capture = json_decode($response, true);

        if (isset($capture['status']) && $capture['status'] === 'COMPLETED') {
            $userId = $capture['purchase_units'][0]['payments']['captures'][0]['custom_id'] ?? 1;
            $amount = $capture['purchase_units'][0]['payments']['captures'][0]['amount']['value'];
            $tokens = $amount * 100;

            $db = \Config\Database::connect();

            // Update transaction to completed
            $db->table('transactions')
                ->where('gateway_ref', $orderId)
                ->update([
                    'type' => 'credit',
                    'status' => 'completed',
                    'description' => "PayPal payment of $$amount completed",
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

            // Add tokens to user
            $userModel = new \App\Models\UserModel();
            $user = $userModel->find($userId);
            if ($user) {
                $newBalance = ($user['token_balance'] ?? 0) + $tokens;
                $userModel->update(['id' => $userId], ['token_balance' => $newBalance]);
            }

            return redirect()->to('/')->with('success', "Payment successful! $tokens tokens added.");
        }

        return redirect()->to('/')->with('error', 'Payment capture failed. Please contact support.');
    }

    public function paypalCancel()
    {
        return redirect()->to('/')->with('error', 'PayPal payment was cancelled.');
    }

    public function optionsHandler()
    {
        return $this->response
            ->setHeader('Access-Control-Allow-Origin', '*')
            ->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
            ->setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->setStatusCode(200);
    }

    public function testConnection()
    {
        return $this->response->setJSON([
            'status' => 'success',
            'message' => 'Billing endpoint is reachable',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
}