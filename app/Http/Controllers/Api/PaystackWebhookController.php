<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\PaystackWebhookService;

class PaystackWebhookController extends Controller
{
    public function __construct(private PaystackWebhookService $paystackWebhookService)
    {
    }

    public function handle(Request $request)
    {
        $result = $this->paystackWebhookService->handle($request);

        return response()->json($result['body'], $result['status_code']);
    }
}
