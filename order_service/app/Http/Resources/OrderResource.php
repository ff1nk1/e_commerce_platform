<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public order view. Whitelisted fields only: `saga_id` and Stripe identifiers
 * are internal orchestration details and must not leak to clients.
 *
 * @mixin Order
 */
final class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'user_id' => (int) $this->user_id,
            'customer_email' => $this->customer_email,
            'customer_name' => $this->customer_name,
            'status' => $this->statusEnum()->value,
            'cancel_reason' => $this->cancel_reason,
            'total_amount' => (string) $this->total_amount,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
