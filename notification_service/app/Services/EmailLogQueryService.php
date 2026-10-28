<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\NotificationFilters;
use App\Models\EmailLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Date;

final class EmailLogQueryService
{
    /**
     * @return LengthAwarePaginator<int, EmailLog>
     */
    public function getFiltered(NotificationFilters $filters): LengthAwarePaginator
    {
        $query = EmailLog::query();

        $query->when($filters->status !== null && $filters->status !== '', function ($q) use ($filters): void {
            $q->where('status', $filters->status);
        });

        $query->when($filters->email !== null && $filters->email !== '', function ($q) use ($filters): void {
            $q->where('email', $filters->email);
        });

        $query->when($filters->eventType !== null && $filters->eventType !== '', function ($q) use ($filters): void {
            $q->where('event_type', $filters->eventType);
        });

        $query->when($filters->dateFrom !== null && $filters->dateFrom !== '', function ($q) use ($filters): void {
            $date = Date::parse($filters->dateFrom)->startOfDay();
            $q->where('created_at', '>=', $date);
        });

        $query->when($filters->dateTo !== null && $filters->dateTo !== '', function ($q) use ($filters): void {
            $date = Date::parse($filters->dateTo)->endOfDay();
            $q->where('created_at', '<=', $date);
        });

        return $query->latest()->paginate($filters->perPage);
    }
}
