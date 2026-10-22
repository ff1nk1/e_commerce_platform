<?php

namespace App\Services\Order;

use App\Contracts\PaymentGateway;
use App\Domain\OrderStatus;
use App\Events\OrderCancelled;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CancelOrderService
{
    public function __construct(
        private OrderPayloadFactory $payloads,
        private RequestStockRelease $stockRelease,
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

            if ($order === null) {
                throw new NotFoundHttpException('Order not found.');
            }

            $order->assertCancellable();

            $previousStatus = $order->statusEnum();
            $order->cancel();
            $order->save();

            event(new OrderCancelled(
                $this->payloads->notificationPayload($order, ['reason' => 'user_cancelled'])
            ));

            if ($previousStatus === OrderStatus::AwaitingPayment) {
                $this->stockRelease->dispatch($order);
                $wroteRelease = true;
            }

            return $order->load(['items.productSnapshot', 'payments']);
        });

        if ($wroteRelease) {
            $this->stockRelease->recordSagaStep($order);
            $this->expireOpenCheckoutSessions($order);
        }

        return $order;
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
                $this->payments->expireCheckoutSession($sessionId);
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
