<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Contracts\IdempotencyStore;
use App\Contracts\PaymentGateway;
use App\DTO\Orders\IdempotentResponse;
use App\DTO\Payments\CheckoutSession;
use App\Enums\PaymentStatus;
use App\Infrastructure\Stripe\StripeCheckoutSessionParams;
use App\Infrastructure\Stripe\StripeCheckoutUrls;
use App\Infrastructure\Stripe\StripeIdempotencyKey;
use App\Infrastructure\Stripe\StripeLineItems;
use App\Models\Order;
use App\Models\Payment;
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

    public function handle(Order $order, string $idempotencyKey): CheckoutSession
    {
        $scopeKey = $this->idempotency->scopeKey(
            'pay',
            (int) $order->user_id,
            $idempotencyKey,
            (int) $order->id
        );

        $cached = $this->idempotency->get($scopeKey);
        if ($cached instanceof IdempotentResponse) {
            return $this->checkoutFromCache($cached->body);
        }

        if (! $this->idempotency->acquireLock($scopeKey)) {
            for ($attempt = 0; $attempt < self::LOCK_RETRY_ATTEMPTS; $attempt++) {
                Sleep::usleep(self::LOCK_RETRY_SLEEP_MICROS);
                $cached = $this->idempotency->get($scopeKey);
                if ($cached instanceof IdempotentResponse) {
                    return $this->checkoutFromCache($cached->body);
                }
            }

            throw new ConflictHttpException('Payment checkout already in progress for this idempotency key.');
        }

        try {
            $cached = $this->idempotency->get($scopeKey);
            if ($cached instanceof IdempotentResponse) {
                return $this->checkoutFromCache($cached->body);
            }

            $order->loadMissing(['items.productSnapshot', 'payments']);

            $pendingWithSession = $order->payments
                ->where('status', PaymentStatus::Pending->value)
                ->filter(static fn (Payment $payment): bool => filled($payment->stripe_checkout_session_id))
                ->sortByDesc('id')
                ->first();

            $result = $this->createCheckout($order, $pendingWithSession);
            $this->idempotency->put($scopeKey, new IdempotentResponse(200, $result->toArray()));

            return $result;
        } finally {
            $this->idempotency->releaseLock($scopeKey);
        }
    }

    private function createCheckout(Order $order, ?Payment $pendingWithSession): CheckoutSession
    {
        $order->assertPayable();

        if ($pendingWithSession instanceof Payment) {
            try {
                $session = $this->stripe->retrieveCheckoutSession($pendingWithSession->stripe_checkout_session_id);
                $sessionIsReusable = $session->status === 'open' && ! empty($session->url);
                if ($sessionIsReusable) {
                    return new CheckoutSession(
                        (string) $session->url,
                        (int) $pendingWithSession->id,
                    );
                }
            } catch (RuntimeException) {
                // Session unusable — create a new one below.
            }

            $pendingWithSession->status = PaymentStatus::Failed->value;
            $pendingWithSession->save();
        }

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'amount' => $order->total_amount,
            'status' => PaymentStatus::Pending->value,
        ]);

        $lineItems = StripeLineItems::fromOrder($order);
        if ($lineItems->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => ['Order has no items to pay for.'],
            ]);
        }

        $session = $this->stripe->createCheckoutSession(
            StripeCheckoutSessionParams::forPayment(
                $order,
                $payment,
                StripeCheckoutUrls::forOrder($order),
                $lineItems,
            ),
            StripeIdempotencyKey::forOrder($order->id, 'checkout', (string) $payment->id),
        );

        $paymentIntentId = is_string($session->paymentIntent) ? $session->paymentIntent : null;

        $payment->stripe_checkout_session_id = is_string($session->id) ? $session->id : null;
        $payment->stripe_payment_intent_id = $paymentIntentId;
        $payment->save();

        $url = $session->url;
        throw_if(! is_string($url) || $url === '', RuntimeException::class, 'Stripe Checkout Session did not return a URL.');

        return new CheckoutSession($url, (int) $payment->id);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function checkoutFromCache(array $body): CheckoutSession
    {
        return CheckoutSession::fromArray($body);
    }
}
