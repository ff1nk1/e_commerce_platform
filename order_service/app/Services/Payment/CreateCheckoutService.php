<?php

namespace App\Services\Payment;

use App\Contracts\IdempotencyStore;
use App\Contracts\PaymentGateway;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Stripe\StripeCheckoutSessionParams;
use App\Services\Stripe\StripeLineItems;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CreateCheckoutService
{
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
            for ($attempt = 0; $attempt < 2; $attempt++) {
                usleep(50_000);
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

            $existing = $order->payments
                ->where('status', 'pending')
                ->filter(static fn (Payment $payment): bool => filled($payment->stripe_checkout_session_id))
                ->sortByDesc('id')
                ->first();

            $result = $this->createCheckout($order, $existing);
            $this->idempotency->put($scopeKey, 200, $result);

            return $result;
        } finally {
            $this->idempotency->releaseLock($scopeKey);
        }
    }

    /**
     * @return array{checkout_url: string, payment_id: int}
     */
    private function createCheckout(Order $order, ?Payment $existing): array
    {
        $order->assertPayable();

        if ($existing !== null) {
            try {
                $session = $this->stripe->retrieveCheckoutSession($existing->stripe_checkout_session_id);
                if (($session['status'] ?? null) === 'open' && ! empty($session['url'])) {
                    return [
                        'checkout_url' => (string) $session['url'],
                        'payment_id' => (int) $existing->id,
                    ];
                }
            } catch (RuntimeException) {
                // Session unusable — create a new one below.
            }

            $existing->status = 'failed';
            $existing->save();
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

        $payment->stripe_checkout_session_id = $session['id'] ?? null;
        $payment->stripe_payment_intent_id = is_string($session['payment_intent'] ?? null)
            ? $session['payment_intent']
            : null;
        $payment->save();

        $url = $session['url'] ?? null;
        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Stripe Checkout Session did not return a URL.');
        }

        return [
            'checkout_url' => $url,
            'payment_id' => (int) $payment->id,
        ];
    }
}
