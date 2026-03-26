<?php

namespace App\Services;

use App\Models\WalletFunding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaystackWebhookService
{
    private const FAILURE_EVENTS = [
        'charge.failed',
        'paymentrequest.failed',
    ];

    public function __construct(private WalletFundingService $walletFundingService)
    {
    }

    public function handle(Request $request): array
    {
        $paystackSecretKey = config('paystack.secretKey');
        $computedSignature = hash_hmac(
            'sha512',
            $request->getContent(),
            $paystackSecretKey
        );

        $paystackSignature = $request->header('x-paystack-signature');

        if (!$paystackSignature || !hash_equals($computedSignature, $paystackSignature)) {
            Log::warning('Invalid Paystack webhook signature');

            return $this->response('Invalid signature', 400);
        }

        $payload = $request->all();
        $event = $payload['event'] ?? null;
        $data = $payload['data'] ?? null;
        $reference = $data['reference'] ?? null;

        if (!$reference) {
            return $event === 'charge.success'
                ? $this->response('No reference', 400)
                : $this->response('Event received');
        }

        $walletFunding = WalletFunding::where('provider', WalletFunding::PROVIDER_PAYSTACK)
            ->where('provider_reference', $reference)
            ->first();

        if (!$walletFunding) {
            Log::warning('Webhook: Funding record not found', ['reference' => $reference]);

            return $this->response('Funding record not found', 404);
        }

        if ($event !== 'charge.success') {
            $status = $this->mapWebhookEventToFundingStatus($event);

            if ($status && !$walletFunding->isCompleted()) {
                $updatedFunding = $this->walletFundingService->updatePaystackFundingStatus(
                    $walletFunding,
                    $status,
                    is_array($data) ? $data : [],
                    'webhook_response'
                );

                Log::info('Webhook: Funding status updated', [
                    'funding_id' => $updatedFunding->id,
                    'reference' => $reference,
                    'event' => $event,
                    'status' => $status,
                ]);
            }

            return $this->response('Event received');
        }

        if ($walletFunding->isCompleted()) {
            Log::info('Webhook: Funding already processed', ['reference' => $reference]);

            return $this->response('Already processed');
        }

        $completedFunding = $this->walletFundingService->completePaystackFunding(
            $walletFunding,
            is_array($data) ? $data : [],
            $reference,
            'webhook_response'
        );

        Log::info('Webhook: Wallet credited successfully', [
            'funding_id' => $completedFunding->id,
            'user_id' => $completedFunding->user_id,
            'amount' => $completedFunding->amount,
            'reference' => $reference,
        ]);

        return $this->response('Webhook processed');
    }

    private function mapWebhookEventToFundingStatus(?string $event): ?string
    {
        if (in_array($event, self::FAILURE_EVENTS, true)) {
            return WalletFunding::STATUS_FAILED;
        }

        return null;
    }

    private function response(string $message, int $statusCode = 200): array
    {
        return [
            'body' => ['message' => $message],
            'status_code' => $statusCode,
        ];
    }
}
