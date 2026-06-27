<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use App\Models\UserModel;

class PaystackController extends Controller
{
    private $secretKey;

    public function __construct()
    {
        $this->secretKey = getenv('PAYSTACK_SECRET_KEY');
    }

    public function initialize()
    {
        // Read JSON input
        $json = $this->request->getJSON();

        $amountUSD = (float) ($json->amount ?? 0);
        $userId = (int) ($json->user_id ?? 1);
        $email = $json->email ?? 'customer@example.com';

        if ($amountUSD <= 0) {
            return $this->response->setJSON(['status' => 'error', 'message' => 'Invalid amount']);
        }

        // Conversion: 1 USD = 130 KES
        $rate = 130;
        $amountKES = (int) ($amountUSD * $rate);

        $metadata = json_encode([
            'user_id' => $userId,
            'tokens' => $amountUSD * 100
        ]);

        $postData = [
            'amount' => $amountKES,
            'email' => $email,
            'currency' => 'KES',
            'metadata' => $metadata,
            'callback_url' => getenv('PAYSTACK_CALLBACK_URL')
        ];

        $ch = curl_init('https://api.paystack.co/transaction/initialize');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->secretKey,
            'Content-Type: application/json',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        if ($httpCode === 200 && isset($result['status']) && $result['status'] === true) {
            return $this->response->setJSON([
                'status' => 'success',
                'authorization_url' => $result['data']['authorization_url'],
                'reference' => $result['data']['reference']
            ]);
        }

        return $this->response->setJSON([
            'status' => 'error',
            'message' => $result['message'] ?? 'Paystack initialization failed.'
        ]);
    }

    public function callback()
    {
        $reference = $this->request->getGet('reference');
        if (!$reference) {
            return redirect()->to('/')->with('error', 'No payment reference found.');
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.paystack.co/transaction/verify/" . $reference,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer " . $this->secretKey
            ],
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($response, true);

        if ($result['status'] === true && $result['data']['status'] === 'success') {
            $metadata = json_decode($result['data']['metadata'], true);
            $userId = $metadata['user_id'] ?? 1;
            $tokens = $metadata['tokens'] ?? 0;

            $userModel = new UserModel();
            $user = $userModel->find($userId);
            $newBalance = ($user['token_balance'] ?? 0) + $tokens;
            $userModel->update($userId, ['token_balance' => $newBalance]);

            $db = \Config\Database::connect();
            $db->table('transactions')->insert([
                'user_id' => $userId,
                'type' => 'credit',
                'tokens' => $tokens,
                'description' => "Paystack payment of $" . ($tokens / 100),
                'gateway' => 'paystack',
                'gateway_ref' => $reference,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            return redirect()->to('/')->with('success', 'Payment successful! Tokens added.');
        } else {
            return redirect()->to('/')->with('error', 'Payment verification failed.');
        }
    }

    public function webhook()
    {
        $input = file_get_contents('php://input');
        $event = json_decode($input, true);

        if ($event['event'] === 'charge.success') {
            $reference = $event['data']['reference'];
            $metadata = $event['data']['metadata'];
            $userId = $metadata['user_id'] ?? 1;
            $tokens = $metadata['tokens'] ?? 0;

            $userModel = new UserModel();
            $user = $userModel->find($userId);
            $newBalance = ($user['token_balance'] ?? 0) + $tokens;
            $userModel->update($userId, ['token_balance' => $newBalance]);

            $db = \Config\Database::connect();
            $existing = $db->table('transactions')->where('gateway_ref', $reference)->get()->getRow();
            if (!$existing) {
                $db->table('transactions')->insert([
                    'user_id' => $userId,
                    'type' => 'credit',
                    'tokens' => $tokens,
                    'description' => "Paystack webhook payment",
                    'gateway' => 'paystack',
                    'gateway_ref' => $reference,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }
        }

        http_response_code(200);
    }
}