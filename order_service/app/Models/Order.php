<?php

namespace App\Models;

use App\Domain\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Order extends Model
{
    protected $fillable = [
        'saga_id',
        'user_id',
        'customer_email',
        'customer_name',
        'status',
        'total_amount',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'status' => OrderStatus::class,
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function statusEnum(): OrderStatus
    {
        return $this->status instanceof OrderStatus
            ? $this->status
            : OrderStatus::from((string) $this->status);
    }

    public function transitionTo(OrderStatus $next): void
    {
        $current = $this->statusEnum();
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
        if (! $this->statusEnum()->canCancel()) {
            throw ValidationException::withMessages([
                'status' => ['Order cannot be cancelled in status: '.$this->statusEnum()->value],
            ]);
        }
    }

    public function assertPayable(): void
    {
        if (! $this->statusEnum()->canPay()) {
            throw ValidationException::withMessages([
                'status' => ['Order cannot be paid in status: '.$this->statusEnum()->value],
            ]);
        }
    }

    public function assertShippable(): void
    {
        if (! $this->statusEnum()->canShip()) {
            throw ValidationException::withMessages([
                'status' => ['Order can only be shipped from status confirmed, current: '.$this->statusEnum()->value],
            ]);
        }
    }
}
