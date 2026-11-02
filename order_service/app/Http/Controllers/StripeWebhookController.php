<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\PaymentGateway;
use App\DTO\Payments\StripeCheckoutSession;
use App\DTO\Payments\WebhookEvent;
use App\Enums\StripeWebhookType;
use App\Services\Payments\ConfirmPaymentService;
use App\Services\Payments\FailPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use UnexpectedValueException;

final class StripeWebhookController extends Controller
{
    public function __construct(
        private PaymentGateway $stripe,
        private ConfirmPaymentService $confirm,
        private FailPaymentService $fail,
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

        $this->dispatchWebhook($event);

        return response()->json(['received' => true]);
    }

    private function dispatchWebhook(WebhookEvent $event): void
    {
        $session = $event->session;
        if (! $session instanceof StripeCheckoutSession) {
            return;
        }

        match ($event->type) {
            StripeWebhookType::CheckoutSessionCompleted => $this->confirm->handle($session),
            StripeWebhookType::CheckoutSessionExpired => $this->fail->handle($session),
            default => null,
        };
    }

    private function isSignatureVerificationFailure(\Throwable $e): bool
    {
        return class_exists(SignatureVerificationException::class)
            && $e instanceof SignatureVerificationException;
    }
}
