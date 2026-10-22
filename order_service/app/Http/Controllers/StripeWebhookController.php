<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentGateway;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __construct(
        private PaymentGateway $stripe,
        private PaymentService $payments,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');

        try {
            $event = $this->stripe->constructWebhookEvent($payload, $signature);
        } catch (UnexpectedValueException $e) {
            Log::warning('Stripe webhook signature verification failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Invalid signature'], 400);
        } catch (\Throwable $e) {
            if ($this->isSignatureVerificationFailure($e)) {
                Log::warning('Stripe webhook signature verification failed', [
                    'message' => $e->getMessage(),
                ]);

                return response()->json(['message' => 'Invalid signature'], 400);
            }

            throw $e;
        }

        $this->payments->handleWebhookEvent($event);

        return response()->json(['received' => true]);
    }

    private function isSignatureVerificationFailure(\Throwable $e): bool
    {
        return class_exists(SignatureVerificationException::class)
            && $e instanceof SignatureVerificationException;
    }
}
