<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Contracts\PaymentGateway;
use App\Models\Payment;
use App\Services\Stripe\StripeIdempotencyKey;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;

final class RefundOrphanPaymentHandler
{
    public function __construct(
        private PaymentGateway $stripe,
    ) {}

    /**
     * Inbox consumer, not CommandBus: CommandBus opens its own DBAL transaction and
     * cannot join the webhook's Laravel transaction on the same PDO connection.
     */
    #[Asynchronous('inbox')]
    #[EventHandler(endpointId: 'order.refundOrphanPayment')]
    public function onRefund(RefundOrphanPayment $event): void
    {
        $this->stripe->refundPaymentIntent(
            $event->paymentIntentId,
            null,
            StripeIdempotencyKey::forOrder($event->orderId, 'refund', $event->paymentIntentId),
        );

        Payment::query()
            ->whereKey($event->paymentId)
            ->where('status', 'refund_pending')
            ->update(['status' => 'refunded']);
    }
}
