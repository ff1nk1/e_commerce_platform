<?php

declare(strict_types=1);

namespace App\DTO\Payments;

use App\Enums\StripeWebhookType;

final readonly class WebhookEvent
{
    public function __construct(
        public string $id,
        public ?StripeWebhookType $type,
        public ?StripeCheckoutSession $session,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public static function fromArray(array $event): self
    {
        $type = $event['type'] ?? null;
        $data = $event['data'] ?? null;
        $object = is_array($data) ? ($data['object'] ?? null) : null;
        $id = $event['id'] ?? null;

        return new self(
            id: is_string($id) ? $id : '',
            type: is_string($type) ? StripeWebhookType::tryFrom($type) : null,
            session: is_array($object) ? StripeCheckoutSession::fromArray($object) : null,
        );
    }
}
