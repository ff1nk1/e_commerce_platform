<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTO\Notifications\NotificationFilters;
use App\Http\Requests\NotificationsRequest;
use App\Services\Notifications\FindNotificationService;
use App\Services\Notifications\ListNotificationsService;
use App\Services\Notifications\ResendNotificationService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

final class NotificationsController extends Controller
{
    public function __construct(
        private ListNotificationsService $listNotifications,
        private FindNotificationService $findNotification,
        private ResendNotificationService $resendMail,
    ) {}

    public function index(NotificationsRequest $request): JsonResponse
    {
        $filters = new NotificationFilters(
            status: $request->validated('status'),
            email: $request->validated('email'),
            eventType: $request->validated('event_type'),
            dateFrom: $request->validated('date_from'),
            dateTo: $request->validated('date_to'),
            perPage: (int) ($request->validated('per_page') ?? 20),
        );

        return response()->json($this->listNotifications->handle($filters));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->findNotification->handle($id));
    }

    public function resend(string $id): JsonResponse
    {
        $notification = $this->findNotification->handle($id);

        try {
            $result = $this->resendMail->handle($notification);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        if ($result->isSent) {
            return response()->json([
                'message' => 'Уведомление успешно отправлено!',
                'data' => $notification->fresh(),
            ], 200);
        }

        return response()->json([
            'message' => $result->lastError !== '' ? $result->lastError : 'Не удалось отправить уведомление',
        ], 400);
    }
}
