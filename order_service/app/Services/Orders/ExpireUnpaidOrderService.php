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
use App\Models\Order;
use App\Models\Payment;
use App\Saga\FulfillmentEvents;
use App\Saga\OrderCancelRequested;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ExpireUnpaidOrderService
{
    public function __construct(
        private FulfillmentEvents $fulfillment,
        private PaymentGateway $payments,
    ) {}

    public function handle(): int
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
            $order->cancel_reason = CancelReason::PaymentTimeout->value;
            $order->save();

            event(new OrderCancelled(
                OrderNotification::fromOrder($order, CancelReason::PaymentTimeout)
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
