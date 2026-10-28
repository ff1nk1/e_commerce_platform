<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\IdempotencyStore;
use App\Contracts\PaymentGateway;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Stripe\StripeCheckoutSessionParams;
use App\Services\Stripe\StripeLineItems;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CreateCheckoutService
{
    private const LOCK_RETRY_ATTEMPTS = 2;

    private const LOCK_RETRY_SLEEP_MICROS = 50_000;

    public function __construct(
        private PaymentGateway $stripe,
        private IdempotencyStore $idempotency,
    ) {}

    /**
     * @return array{checkout_url: string, payment_id: int}
     */
    public function forOrder(Order $order, string $idempotencyKey): array
    {
        $scopeKey = $this->idempotency->scopeKey(
            'pay',
            (int) $order->user_id,
            $idempotencyKey,
            (int) $order->id
        );

        $cached = $this->idempotency->get($scopeKey);
        if ($cached !== null) {
            /** @var array{checkout_url: string, payment_id: int} $body */
            $body = $cached['body'];

            return $body;
        }

        if (! $this->idempotency->acquireLock($scopeKey)) {
            for ($attempt = 0; $attempt < self::LOCK_RETRY_ATTEMPTS; $attempt++) {
                Sleep::usleep(self::LOCK_RETRY_SLEEP_MICROS);
                $cached = $this->idempotency->get($scopeKey);
                if ($cached !== null) {
                    /** @var array{checkout_url: string, payment_id: int} $body */
                    $body = $cached['body'];

                    return $body;
                }
            }

            throw new ConflictHttpException('Payment checkout already in progress for this idempotency key.');
        }

        try {
            $cached = $this->idempotency->get($scopeKey);
            if ($cached !== null) {
                /** @var array{checkout_url: string, payment_id: int} $body */
                $body = $cached['body'];

                return $body;
            }

            $order->loadMissing(['items.productSnapshot', 'payments']);

            $pendingWithSession = $order->payments
                ->where('status', 'pending')
                ->filter(static fn (Payment $payment): bool => filled($payment->stripe_checkout_session_id))
                ->sortByDesc('id')
                ->first();

            $result = $this->createCheckout($order, $pendingWithSession);
            $this->idempotency->put($scopeKey, 200, $result);

            return $result;
        } finally {
            $this->idempotency->releaseLock($scopeKey);
        }
    }

    /**
     * @return array{checkout_url: string, payment_id: int}
     */
    private function createCheckout(Order $order, ?Payment $pendingWithSession): array
    {
        $order->assertPayable();

        if ($pendingWithSession instanceof Payment) {
            try {
                $session = $this->stripe->retrieveCheckoutSession($pendingWithSession->stripe_checkout_session_id);
                $sessionIsReusable = ($session['status'] ?? null) === 'open' && ! empty($session['url']);
                if ($sessionIsReusable) {
                    return [
                        'checkout_url' => (string) $session['url'],
                        'payment_id' => (int) $pendingWithSession->id,
                    ];
                }
            } catch (RuntimeException) {
                // Session unusable — create a new one below.
            }

            $pendingWithSession->status = 'failed';
            $pendingWithSession->save();
        }

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'amount' => $order->total_amount,
            'status' => 'pending',
        ]);

        $lineItems = StripeLineItems::fromOrder($order);
        if ($lineItems === []) {
            throw ValidationException::withMessages([
                'items' => ['Order has no items to pay for.'],
            ]);
        }

        $session = $this->stripe->createCheckoutSession(
            StripeCheckoutSessionParams::forPayment($order, $payment, $lineItems)
        );

        $paymentIntentId = is_string($session['payment_intent'] ?? null)
            ? $session['payment_intent']
            : null;

        $payment->stripe_checkout_session_id = $session['id'] ?? null;
        $payment->stripe_payment_intent_id = $paymentIntentId;
        $payment->save();

        $url = $session['url'] ?? null;
        throw_if(! is_string($url) || $url === '', RuntimeException::class, 'Stripe Checkout Session did not return a URL.');

        return [
            'checkout_url' => $url,
            'payment_id' => (int) $payment->id,
        ];
    }
}
