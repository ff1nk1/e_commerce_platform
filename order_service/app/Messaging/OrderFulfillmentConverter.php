<?php

declare(strict_types=1);

namespace App\Messaging;

use Ecotone\Messaging\Attribute\MediaTypeConverter;
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;
use JsonException;

#[MediaTypeConverter]
final class OrderFulfillmentConverter implements Converter
{
    /**
     * @param  mixed  $source
     */
    public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): OrderFulfillment|string
    {
        if ($source instanceof OrderFulfillment) {
            return json_encode(
                [
                    'sagaId' => $source->sagaId,
                    'state' => $source->state,
                    'releaseRequested' => $source->releaseRequested,
                ],
                JSON_THROW_ON_ERROR,
            );
        }

        throw_unless(is_string($source), JsonException::class, 'Order fulfillment document must be a JSON string.');

        $data = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        throw_if(! is_array($data) || ! isset($data['sagaId'], $data['state']), JsonException::class, 'Order fulfillment document is missing sagaId or state.');

        return OrderFulfillment::restore(
            (string) $data['sagaId'],
            (string) $data['state'],
            (bool) ($data['releaseRequested'] ?? false),
        );
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        $fulfillment = Type::object(OrderFulfillment::class);
        $string = Type::string();

        $toJson = $sourceMediaType->isCompatibleWithParsed(MediaType::APPLICATION_X_PHP)
            && $targetMediaType->isCompatibleWithParsed(MediaType::APPLICATION_JSON)
            && $sourceType->isCompatibleWith($fulfillment)
            && $targetType->isCompatibleWith($string);

        $fromJson = $sourceMediaType->isCompatibleWithParsed(MediaType::APPLICATION_JSON)
            && $targetMediaType->isCompatibleWithParsed(MediaType::APPLICATION_X_PHP)
            && $sourceType->isCompatibleWith($string)
            && $targetType->isCompatibleWith($fulfillment);

        return $toJson || $fromJson;
    }
}
