<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PaystackService
{
    protected $secretKey;
    protected $paymentUrl;

    public function __construct()
    {
        $this->secretKey = config('paystack.secretKey');
        $this->paymentUrl = config('paystack.paymentUrl');
    }

    public function initializePayment(array $data)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Content-Type'  => 'application/json',
        ])->post($this->paymentUrl . '/transaction/initialize', [
            'email'    => $data['email'],
            'amount'   => $data['amount'] * 100, // Paystack uses kobo
            'callback_url' => $data['callback_url'],
            'metadata' => [
                'user_id' => $data['user_id'],
            ],
        ]);

        return $response->json();
    }

    public function verifyPayment(string $reference)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
        ])->get($this->paymentUrl . '/transaction/verify/' . $reference);

        return $response->json();
    }
}