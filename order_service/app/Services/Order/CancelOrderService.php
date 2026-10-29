<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Contracts\PaymentGateway;
use App\Domain\OrderStatus;
use App\Events\OrderCancelled;
use App\Messaging\FulfillmentEvents;
use App\Messaging\OrderCancelRequested;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Stripe\StripeIdempotencyKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CancelOrderService
{
    public function __construct(
        private OrderPayloadFactory $payloads,
        private FulfillmentEvents $fulfillment,
        private PaymentGateway $payments,
    ) {}

    public function forUser(int $orderId, int $userId): Order
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
            $order->cancel_reason = 'user_cancelled';
            $order->save();

            event(new OrderCancelled(
                $this->payloads->notificationPayload($order, ['reason' => 'user_cancelled'])
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

    public function expireUnpaid(): int
    {
        $timeoutMinutes = (int) config('orders.unpaid_timeout_minutes', 15);
        $cutoff = now()->subMinutes($timeoutMinutes);
        $expired = 0;
        $lastId = 0;

        do {
            $ids = Order::query()
                ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::AwaitingPayment->value])
                ->where('created_at', '<=', $cutoff)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(100)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            foreach ($ids as $id) {
                $lastId = (int) $id;
                if ($this->expireOne($lastId)) {
                    $expired++;
                }
            }
        } while (true);

        return $expired;
    }

    private function expireOne(int $orderId): bool
    {
        $expireSessions = false;

        $order = DB::transaction(function () use ($orderId, &$expireSessions): ?Order {
            $order = Order::query()->lockForUpdate()->find($orderId);
            if (! $order instanceof Order) {
                return null;
            }

            $previousStatus = $order->statusEnum();
            if (! in_array($previousStatus, [OrderStatus::Pending, OrderStatus::AwaitingPayment], true)) {
                return null;
            }

            $order->cancel();
            $order->cancel_reason = 'payment_timeout';
            $order->save();

            event(new OrderCancelled(
                $this->payloads->notificationPayload($order, ['reason' => 'payment_timeout'])
            ));

            $sagaId = is_string($order->saga_id) ? $order->saga_id : '';
            if ($sagaId === '') {
                Log::error('Unpaid order expired without saga_id; stock release was not requested', [
                    'order_id' => $order->id,
                ]);
            }
            $this->fulfillment->publish(new OrderCancelRequested($sagaId), $sagaId);
            $expireSessions = $previousStatus === OrderStatus::AwaitingPayment;

            return $order;
        });

        if (! $order instanceof Order) {
            return false;
        }

        if ($expireSessions) {
            $this->expireOpenCheckoutSessions($order);
        }

        return true;
    }

    private function expireOpenCheckoutSessions(Order $order): void
    {
        $order->loadMissing('payments');

        $openCheckoutPayments = $order->payments
            ->where('status', 'pending')
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
