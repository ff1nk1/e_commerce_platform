<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Notification;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FindNotificationService
{
    public function handle(string $id): Notification
    {
        $notification = Notification::query()->find($id);

        throw_if($notification === null, NotFoundHttpException::class, 'Notification not found.');

        return $notification;
    }
}
