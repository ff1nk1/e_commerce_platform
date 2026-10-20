<?php

namespace App\Services\Payment;

use App\Contracts\IdempotencyStore;
use App\Contracts\PaymentGateway;
use App\Domain\Money;
use App\Models\Payment;
use App\Services\Order\OrderQueryService;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CreateCheckoutService
{
    public function __construct(
        private OrderQueryService $orders,
        private PaymentGateway $stripe,
        private IdempotencyStore $idempotency,
    ) {}

    /**
     * @return array{checkout_url: string, payment_id: int}
     */
    public function forUser(int $orderId, int $userId, string $idempotencyKey): array
    {
        $scopeKey = $this->idempotency->scopeKey('pay', $userId, $idempotencyKey, $orderId);

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

            $result = $this->createCheckout($orderId, $userId);
            $this->idempotency->put($scopeKey, 200, $result);

            return $result;
        } finally {
            $this->idempotency->releaseLock($scopeKey);
        }
    }

    /**
     * @return array{checkout_url: string, payment_id: int}
     */
    private function createCheckout(int $orderId, int $userId): array
    {
        $order = $this->orders->findForUser($orderId, $userId);
        $order->loadMissing(['items.productSnapshot']);
        $order->assertPayable();

        $existing = Payment::query()
            ->where('order_id', $order->id)
            ->where('status', 'pending')
            ->whereNotNull('stripe_checkout_session_id')
            ->latest('id')
            ->first();

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

        $lineItems = [];
        foreach ($order->items as $item) {
            $name = ($item->productSnapshot !== null ? $item->productSnapshot->name : null)
                ?? ('Product #'.$item->product_id);
            $unitAmount = Money::of((string) $item->unit_price)->toMinorUnits();

            $lineItems[] = [
                'quantity' => (int) $item->quantity,
                'price_data' => [
                    'currency' => 'rub',
                    'unit_amount' => $unitAmount,
                    'product_data' => [
                        'name' => $name,
                    ],
                ],
            ];
        }

        if ($lineItems === []) {
            throw ValidationException::withMessages([
                'items' => ['Order has no items to pay for.'],
            ]);
        }

        $successUrl = str_replace(
            '{ORDER_ID}',
            (string) $order->id,
            (string) config('services.stripe.success_url')
        );
        $cancelUrl = str_replace(
            '{ORDER_ID}',
            (string) $order->id,
            (string) config('services.stripe.cancel_url')
        );

        $session = $this->stripe->createCheckoutSession([
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $order->id,
            'metadata' => [
                'order_id' => (string) $order->id,
                'payment_id' => (string) $payment->id,
            ],
            'expires_at' => time() + 30 * 60,
            'line_items' => $lineItems,
        ]);

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
