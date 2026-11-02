<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Contracts\PaymentGateway;
use App\DTO\Orders\OrderNotification;
use App\Enums\CancelReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderCancelled;
use App\Infrastructure\Stripe\StripeIdempotencyKey;
use App\Messaging\FulfillmentEvents;
use App\Messaging\OrderCancelRequested;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CancelOrderService
{
    public function __construct(
        private FulfillmentEvents $fulfillment,
        private PaymentGateway $payments,
    ) {}

    public function handle(int $orderId, int $userId): Order
    {
        $wroteRelease = false;

        $order = DB::transaction(function () use ($orderId, $userId, &$wroteRelease) {
            $order = Order::query()
                ->with(['items.productSnapshot', 'payments'])
                ->where('id', $orderId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            throw_if($order === null, NotFoundHttpException::class, 'Order not found.');

            $order->assertCancellable();

            $previousStatus = $order->statusEnum();
            $order->cancel();
            $order->cancel_reason = CancelReason::UserCancelled->value;
            $order->save();

            event(new OrderCancelled(
                OrderNotification::fromOrder($order, CancelReason::UserCancelled)
            ));

            if ($previousStatus === OrderStatus::AwaitingPayment) {
                $sagaId = is_string($order->saga_id) ? $order->saga_id : '';
                $this->fulfillment->publish(new OrderCancelRequested($sagaId), $sagaId);
                $wroteRelease = true;
            }

            return $order->load(['items.productSnapshot', 'payments']);
        });

        if ($wroteRelease) {
            $this->expireOpenCheckoutSessions($order);
        }

        return $order;
    }

    private function expireOpenCheckoutSessions(Order $order): void
    {
        $order->loadMissing('payments');

        $openCheckoutPayments = $order->payments
            ->where('status', PaymentStatus::Pending->value)
            ->filter(static fn (Payment $payment): bool => filled($payment->stripe_checkout_session_id));

        foreach ($openCheckoutPayments as $payment) {
            $sessionId = $payment->stripe_checkout_session_id;
            if (! is_string($sessionId) || $sessionId === '') {
                continue;
            }

            try {
                $this->payments->expireCheckoutSession(
                    $sessionId,
                    StripeIdempotencyKey::forOrder($order->id, 'expire', $sessionId),
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to expire Stripe checkout session on cancel', [
                    'order_id' => $order->id,
                    'payment_id' => $payment->id,
                    'session_id' => $sessionId,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
