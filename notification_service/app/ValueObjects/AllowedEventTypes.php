<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\NotificationEventType;

final readonly class AllowedEventTypes
{
    /**
     * @param  list<NotificationEventType>  $types
     */
    private function __construct(
        private array $types,
    ) {}

    public static function of(NotificationEventType ...$types): self
    {
        return new self(array_values($types));
    }

    /**
     * @return list<NotificationEventType>
     */
    public function all(): array
    {
        return $this->types;
    }

    public function contains(string $eventType): bool
    {
        foreach ($this->types as $type) {
            if ($type->value === $eventType) {
                return true;
            }
        }

        return false;
    }
}
