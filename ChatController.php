<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use App\Services\AiAssistantService;
use Stripe\Stripe;
use Stripe\PaymentIntent;

class ChatController extends Controller
{
    private $supportDocumentId = 8; // Change 8 to your actual user manual's document ID
    private $cache;

    public function __construct()
    {
        $this->cache = \Config\Services::cache();
    }

    // GET /chat/{id} - Loads the UI
    public function index($id)
    {
        $db = \Config\Database::connect();
        $userModel = new \App\Models\UserModel();

        // Cache only the metadata (exclude the huge 'content' field)
        $cacheKey = 'doc_meta_' . $id;
        $document = cache($cacheKey);

        if (!$document) {
            $document = $db->table('documents')
                ->select('id, original_name, category, created_at')
                ->where('id', $id)
                ->get()
                ->getRow();

            if ($document) {
                cache()->save($cacheKey, $document, 300);
            }
        }

        $user = $userModel->find(1);

        if (!$document) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        return view('upload/chat', [
            'document' => $document,
            'user' => $user ?? ['token_balance' => 0]
        ]);
    }

    public function _remap($method, ...$params)
    {
        // Set CORS headers for all requests
        $this->response->setHeader('Access-Control-Allow-Origin', 'http://localhost');
        $this->response->setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS, PUT, DELETE');
        $this->response->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        $this->response->setHeader('Access-Control-Allow-Credentials', 'true');

        // Handle preflight OPTIONS request immediately
        if ($this->request->getMethod(true) === 'OPTIONS') {
            $this->response->setStatusCode(200);
            $this->response->send();
            exit;
        }

        // If the method exists, call it
        if (method_exists($this, $method)) {
            return $this->$method(...$params);
        }
        throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
    }

    // POST /ask - The AJAX Endpoint
    public function ask()
    {
        $question = $this->request->getPost('question');
        $documentId = $this->request->getPost('document_id');
        $mode = $this->request->getPost('mode') ?? 'qa';   // ← get mode
        $userId = 1;

        $isSupportBot = ($documentId == $this->supportDocumentId);
        $aiService = new \App\Services\AiAssistantService();
        $userModel = new \App\Models\UserModel();
        $user = $userModel->find($userId);
        $currentBalance = $user['token_balance'] ?? 0;

        // ---------- CHART MODE ----------
        if ($mode === 'chart') {
            $chartData = $aiService->getChartData($question, $documentId);
            // No token deduction for charts (optional: deduct small fee)
            $newBalance = $currentBalance; // unchanged

            return $this->response->setJSON([
                'status' => 'success',
                'mode' => 'chart',
                'chart' => $chartData,
                'text' => 'Here is the chart you requested.',
                'new_balance' => $newBalance
            ]);
        }

        // ---------- QA MODE (existing logic) ----------
        $answer = $aiService->askQuestion($question, $documentId);

        if (!$isSupportBot) {
            $inputTokens = (int) ceil(str_word_count($question) * 1.33);
            $outputTokens = (int) ceil(str_word_count($answer) * 1.33);
            $totalTokensUsed = $inputTokens + $outputTokens;
            $newBalance = max(0, $currentBalance - $totalTokensUsed);
            $userModel->updateTokens($userId, $newBalance);

            $db = \Config\Database::connect();
            $db->table('transactions')->insert([
                'user_id' => $userId,
                'type' => 'debit',
                'tokens' => $totalTokensUsed,
                'description' => "AI Query (Doc ID: $documentId)",
                'gateway' => 'system'
            ]);
        } else {
            $newBalance = $currentBalance;
        }

        return $this->response->setJSON([
            'status' => 'success',
            'text' => $answer,
            'new_balance' => $newBalance
        ]);
    }

    public function topup()
    {
        $userModel = new \App\Models\UserModel();
        $userId = 1;
        $user = $userModel->find($userId);

        if ($user) {
            $newBalance = ($user['token_balance'] ?? 0) + 500;
            $userModel->updateTokens($userId, $newBalance);

            return $this->response->setJSON([
                'status' => 'success',
                'new_balance' => $newBalance
            ]);
        }

        return $this->response->setJSON(['status' => 'error'], 400);
    }

    public function getBalance()
    {
        $userModel = new \App\Models\UserModel();
        $user = $userModel->find(1);
        return $this->response->setJSON(['balance' => $user['token_balance'] ?? 0]);
    }

    public function initiateMpesa()
    {

        // Allow from any origin (or restrict to your frontend)
        header("Access-Control-Allow-Origin: http://localhost");
        header("Access-Control-Allow-Methods: POST, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type");
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
        $phone = $this->request->getPost('phone');
        $amount = (int) ($this->request->getPost('amount') ?? 1);

        // Clean phone number
        $phone = preg_replace('/\s+/', '', $phone);
        if (substr($phone, 0, 1) === '0') {
            $phone = '254' . substr($phone, 1);
        }
        if (substr($phone, 0, 4) === '+254') {
            $phone = substr($phone, 1);
        }

        // Generate unique checkout ID
        $checkoutId = 'ws_CO_' . date('YmdHis') . rand(1000, 9999);
        $tokensToAdd = $amount * 100;

        $db = \Config\Database::connect();

        // ✅ SAVE PENDING TRANSACTION FIRST (using your existing columns + new gateway_ref/status)
        $inserted = $db->table('transactions')->insert([
            'user_id' => 1,
            'type' => 'pending',      // we add 'pending' to the enum later
            'status' => 'pending',
            'amount_usd' => 0,
            'tokens' => $tokensToAdd,
            'description' => "M-Pesa payment initiated for KES $amount",
            'gateway' => 'mpesa',
            'gateway_ref' => $checkoutId,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        if (!$inserted) {
            return $this->response->setJSON([
                'ResponseCode' => '1',
                'CustomerMessage' => 'Failed to create transaction record.'
            ]);
        }

        // ========== YOUR EXISTING MPESA API CALLS (unchanged) ==========
        $consumerKey = getenv('MPESA_CONSUMER_KEY') ?: 'otCAlsPZWtY4MXGklxvP53MUpEhckadT0zjFA1D8IO2bbRGY';
        $consumerSecret = getenv('MPESA_CONSUMER_SECRET') ?: 'PKGx5mnArPKkliC0mlfGkMC6sS4bVMjaLWpkAdHAJZfCwcyLZyQ1XNOf9YuQYy5d';
        $BusinessShortCode = getenv('MPESA_SHORTCODE') ?: '174379';
        $Passkey = getenv('MPESA_PASSKEY') ?: 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919';
        $ngrokUrl = getenv('MPESA_CALLBACK_URL') ?: 'https://uniconoclastically-cattleless-myong.ngrok-free.dev';
        $ngrokUrl = rtrim($ngrokUrl, '/');

        // Get Access Token
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

        if ($httpCode !== 200) {
            // Delete pending transaction on auth failure
            $db->table('transactions')->where('gateway_ref', $checkoutId)->delete();
            return $this->response->setJSON([
                'ResponseCode' => '1',
                'CustomerMessage' => 'Payment service unavailable. Please try again.'
            ]);
        }

        $result = json_decode($response);
        if (!isset($result->access_token)) {
            $db->table('transactions')->where('gateway_ref', $checkoutId)->delete();
            return $this->response->setJSON([
                'ResponseCode' => '1',
                'CustomerMessage' => 'Authentication failed.'
            ]);
        }

        $accessToken = $result->access_token;

        // STK Push
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
            'CallBackURL' => $ngrokUrl . '/mpesa-callback',
            'AccountReference' => 'DocuWisePay',
            'TransactionDesc' => 'Token Topup'
        ];

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
        curl_close($ch);

        $responseData = json_decode($stkResponse, true);
        // ========== END OF EXISTING API CALLS ==========

        if ($responseData && isset($responseData['ResponseCode']) && $responseData['ResponseCode'] == '0') {
            $realCheckoutId = $responseData['CheckoutRequestID'];
            // Update the pending transaction with real MerchantRequestID
            $db->table('transactions')->where('gateway_ref', $checkoutId)->update([
                'gateway_ref' => $realCheckoutId
            ]);
            return $this->response->setJSON([
                'ResponseCode' => '0',
                'CustomerMessage' => 'STK Push sent. Check your phone.',
                'CheckoutRequestID' => $realCheckoutId
            ]);
        } else {
            // Delete the pending transaction if STK push failed
            $db->table('transactions')->where('gateway_ref', $checkoutId)->delete();
            $errorMsg = $responseData['errorMessage'] ?? 'STK Push failed. Please try again.';
            return $this->response->setJSON([
                'ResponseCode' => '1',
                'CustomerMessage' => $errorMsg
            ]);
        }
    }

    // FIXED: mpesaStatus without gateway_ref
    public function mpesaStatus()
    {
        $checkoutId = $this->request->getGet('checkoutId');
        if (!$checkoutId) {
            return $this->response->setJSON(['status' => 'unknown']);
        }

        $db = \Config\Database::connect();

        // Find transaction by gateway_ref and pending status
        $tx = $db->table('transactions')
            ->where('gateway_ref', $checkoutId)
            ->where('status', 'pending')
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getRow();

        if (!$tx) {
            return $this->response->setJSON(['status' => 'unknown']);
        }

        $createdTime = strtotime($tx->created_at);
        $now = time();

        // Auto-complete after 30 seconds (sandbox)
        if (($now - $createdTime) >= 30) {
            // Mark transaction as completed
            $db->table('transactions')->where('id', $tx->id)->update([
                'type' => 'credit',
                'status' => 'completed',
                'description' => 'M-Pesa Payment Completed (Sandbox)'
            ]);

            // Add tokens to user
            $userModel = new \App\Models\UserModel();
            $user = $userModel->find(1);
            if ($user) {
                $newBalance = ($user['token_balance'] ?? 0) + $tx->tokens;
                $userModel->update(['id' => 1], ['token_balance' => $newBalance]);
            }

            return $this->response->setJSON([
                'status' => 'completed',
                'tokens' => $tx->tokens
            ]);
        }

        return $this->response->setJSON(['status' => 'pending']);
    }

    // FIXED: simulateMpesaCallback without gateway_ref
    public function simulateMpesaCallback()
    {
        $checkoutId = $this->request->getGet('checkoutId');
        $amount = $this->request->getGet('amount') ?? 5;

        if (!$checkoutId) {
            return $this->response->setJSON(['error' => 'Missing checkoutId']);
        }

        $tokens = $amount * 100;
        $userModel = new \App\Models\UserModel();
        $user = $userModel->find(1);

        if ($user) {
            $newBalance = ($user['token_balance'] ?? 0) + $tokens;
            $userModel->update(['id' => 1], ['token_balance' => $newBalance]);

            $db = \Config\Database::connect();

            // Check if there's a pending transaction
            $pending = $db->table('transactions')
                ->where('type', 'pending')
                ->where('gateway', 'mpesa')
                ->orderBy('created_at', 'DESC')
                ->get()
                ->getRow();

            if ($pending) {
                $db->table('transactions')
                    ->where('id', $pending->id)
                    ->update([
                        'type' => 'credit',
                        'description' => "M-Pesa Payment of KES $amount (Completed)",
                        'tokens' => $tokens
                    ]);
            } else {
                // Insert new credit transaction
                $db->table('transactions')->insert([
                    'user_id' => 1,
                    'type' => 'credit',
                    'amount_usd' => 0,
                    'tokens' => $tokens,
                    'description' => "M-Pesa Payment of KES $amount",
                    'gateway' => 'mpesa',
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }

            return $this->response->setJSON([
                'status' => 'success',
                'message' => 'Payment completed',
                'new_balance' => $newBalance
            ]);
        }

        return $this->response->setJSON(['status' => 'error', 'message' => 'User not found']);
    }

    public function support()
    {
        // The ID of your uploaded user manual (replace 5 with your actual ID)
        $manualDocumentId = 8; // ← CHANGE THIS TO YOUR DOCUMENT ID

        // Fetch document metadata (optional, just for display)
        $db = \Config\Database::connect();
        $document = $db->table('documents')
            ->select('id, original_name, category, created_at')
            ->where('id', $manualDocumentId)
            ->get()
            ->getRow();

        if (!$document) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound('User manual not found. Please upload it first.');
        }

        // Get current user's token balance (if you want to show it)
        $userModel = new \App\Models\UserModel();
        $user = $userModel->find(1);

        return view('support', [
            'document' => $document,
            'user' => $user ?? ['token_balance' => 0],
            'fixed_document_id' => $manualDocumentId  // Pass to view
        ]);
    }

    // FIXED: mpesaCallback without gateway_ref
    public function mpesaCallback()
    {
        $db = \Config\Database::connect();
        $callbackData = file_get_contents('php://input');
        log_message('debug', 'M-Pesa Callback: ' . $callbackData);
        $data = json_decode($callbackData, true);

        if (isset($data['Body']['stkCallback']['ResultCode'])) {
            $resultCode = $data['Body']['stkCallback']['ResultCode'];
            $resultDesc = $data['Body']['stkCallback']['ResultDesc'] ?? '';
            $merchantId = $data['Body']['stkCallback']['MerchantRequestID'] ?? null;

            if ($merchantId) {
                $tx = $db->table('transactions')
                    ->where('gateway_ref', $merchantId)
                    ->where('status', 'pending')
                    ->get()
                    ->getRow();

                if ($tx) {
                    if ($resultCode == 0) {
                        // Success – calculate amount from tokens
                        $amount = $tx->tokens / 100;
                        $db->table('transactions')->where('id', $tx->id)->update([
                            'type' => 'credit',
                            'status' => 'completed',
                            'description' => "M-Pesa Topup of KES $amount"
                        ]);

                        $userModel = new \App\Models\UserModel();
                        $user = $userModel->find(1);
                        if ($user) {
                            $newBalance = ($user['token_balance'] ?? 0) + $tx->tokens;
                            $userModel->update(['id' => 1], ['token_balance' => $newBalance]);
                        }
                        log_message('debug', "✅ M-Pesa success: added {$tx->tokens} tokens");
                    } else {
                        // Failure
                        $db->table('transactions')->where('id', $tx->id)->update([
                            'status' => 'failed',
                            'description' => "Failed: $resultDesc"
                        ]);
                        log_message('error', "❌ M-Pesa failed: $resultDesc");
                    }
                } else {
                    log_message('error', "No pending transaction found for merchantId: $merchantId");
                }
            }
        }

        return $this->response->setJSON(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
    public function demoAddTokens()
    {
        // Allow from any origin (or restrict to your frontend)
        header("Access-Control-Allow-Origin: http://localhost");
        header("Access-Control-Allow-Methods: POST, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type");
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
        try {
            $db = \Config\Database::connect();  // ✅ ADD THIS LINE

            // Get current balance using raw query
            $query = $db->query("SELECT token_balance FROM users WHERE id = 1");
            $user = $query->getRow();

            if (!$user) {
                return $this->response->setJSON([
                    'status' => 'error',
                    'message' => 'User not found'
                ]);
            }

            $currentBalance = $user->token_balance ?? 0;
            $newBalance = $currentBalance + 500;

            // Update using raw query
            $db->query("UPDATE users SET token_balance = ? WHERE id = 1", [$newBalance]);

            // Insert transaction
            $db->query("INSERT INTO transactions (user_id, type, amount_usd, tokens, description, gateway, created_at) 
                    VALUES (1, 'credit', 0, 500, 'Demo Mode - Presentation Tokens', 'demo', NOW())");

            return $this->response->setJSON([
                'status' => 'success',
                'new_balance' => $newBalance
            ]);

        } catch (\Exception $e) {
            log_message('error', 'DemoAddTokens Error: ' . $e->getMessage());
            return $this->response->setJSON([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }
    public function createStripeIntent()
    {
        Stripe::setApiKey(getenv('STRIPE_SECRET_KEY') ?? 'sk_test_51TKECuAivi8vOtoBVKtqEbxjrm7upsiXEwZZo3yP1yCSArRxthtLcikmQ75fHl9oyEAHn2Fwp6soTxdDSOKDgI2G00HQCx2sVG');

        try {
            $intent = PaymentIntent::create([
                'amount' => 500,
                'currency' => 'usd',
                'automatic_payment_methods' => ['enabled' => true],
                'metadata' => ['user_id' => 1],
            ]);

            return $this->response->setJSON([
                'clientSecret' => $intent->client_secret
            ]);
        } catch (\Exception $e) {
            return $this->response->setJSON(['error' => $e->getMessage()], 500);
        }
    }

    public function testGemini()
    {
        $aiService = new AiAssistantService();
        $result = $aiService->askQuestion("Say hello, just testing", null);
        echo "<pre>";
        print_r($result);
        echo "</pre>";
    }

    public function clear()
    {
        $this->cache->clean();
        session()->destroy();
        return redirect()->to('/');
    }
}