<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\OrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * @property OrderStatus $status
 * @property-read Collection<int, OrderItem> $items
 */
#[Fillable([
    'saga_id',
    'user_id',
    'customer_email',
    'customer_name',
    'status',
    'cancel_reason',
    'total_amount',
])]
final class Order extends Model
{
    use HasFactory;

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function statusEnum(): OrderStatus
    {
        return $this->status;
    }

    public function transitionTo(OrderStatus $next): void
    {
        $current = $this->status;
        if (! $current->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition order from {$current->value} to {$next->value}."],
            ]);
        }

        $this->status = $next;
    }

    public function markAwaitingPayment(): void
    {
        $this->transitionTo(OrderStatus::AwaitingPayment);
    }

    public function confirm(): void
    {
        $this->transitionTo(OrderStatus::Confirmed);
    }

    public function cancel(): void
    {
        $this->transitionTo(OrderStatus::Cancelled);
    }

    public function failPayment(): void
    {
        $this->transitionTo(OrderStatus::PaymentFailed);
    }

    public function ship(): void
    {
        $this->transitionTo(OrderStatus::Shipped);
    }

    public function assertCancellable(): void
    {
        if (! $this->status->canCancel()) {
            throw ValidationException::withMessages([
                'status' => ['Order cannot be cancelled in status: '.$this->status->value],
            ]);
        }
    }

    public function assertPayable(): void
    {
        if (! $this->status->canPay()) {
            throw ValidationException::withMessages([
                'status' => ['Order cannot be paid in status: '.$this->status->value],
            ]);
        }
    }

    public function assertShippable(): void
    {
        if (! $this->status->canShip()) {
            throw ValidationException::withMessages([
                'status' => ['Order can only be shipped from status confirmed, current: '.$this->status->value],
            ]);
        }
    }

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'status' => OrderStatus::class,
        ];
    }
}
