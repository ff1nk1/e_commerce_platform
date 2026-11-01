<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTO\Notifications\NotificationFilters;
use App\Enums\EmailStatus;
use App\Http\Requests\NotificationsRequest;
use App\Models\EmailLog;
use App\Services\Mail\MailSender;
use App\Services\Mail\ResendNotificationMailService;
use App\Services\Notifications\ListEmailLogsService;
use Illuminate\Http\JsonResponse;

final class NotificationsController extends Controller
{
    public function __construct(
        private ListEmailLogsService $listEmailLogs,
        private ResendNotificationMailService $resendMail,
        private MailSender $mailSender,
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

        return response()->json($this->listEmailLogs->handle($filters));
    }

    public function show(string $id): JsonResponse
    {
        $notification = EmailLog::query()->findOrFail($id);

        return response()->json($notification);
    }

    public function resend(string $id): JsonResponse
    {
        $notification = EmailLog::query()->findOrFail($id);

        if ($notification->status !== EmailStatus::Failed->value) {
            return response()->json([
                'message' => 'Повторная отправка доступна только для записей со статусом failed.',
            ], 422);
        }

        if ($this->mailSender->resolveEventTypeForResend($notification) === null) {
            return response()->json([
                'message' => 'У записи нет event_type, переотправка невозможна.',
            ], 422);
        }

        $isSent = $this->resendMail->handle($notification);

        if ($isSent) {
            return response()->json([
                'message' => 'Уведомление успешно отправлено!',
                'data' => $notification->fresh(),
            ], 200);
        }

        $notification->refresh();

        return response()->json([
            'message' => $notification->error_message ?? 'Не удалось отправить уведомление',
        ], 400);
    }
}
