<?php

namespace App\Listeners;

use App\Events\UserRegistered;
use App\Services\Kafka\KafkaPublisher;
use Illuminate\Support\Facades\Log;

class PublishUserRegisteredToKafka
{
    public function __construct(
        private KafkaPublisher $kafka,
    ) {}

    public function handle(UserRegistered $event): void
    {
        $user = $event->user;

        try {
            $registeredAt = $user->created_at?->toIso8601String() ?? now()->toIso8601String();

            $this->kafka->publish(
                'user.events',
                (string) $user->id,
                [
                    'event-type' => 'user.registered',
                    'source' => 'auth-service',
                ],
                [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'name' => $user->name,
                    'registered_at' => $registeredAt,
                    'event_type' => 'user.registered',
                ],
            );
        } catch (\Throwable $e) {
            Log::error('Kafka publish failed on user.registered', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
