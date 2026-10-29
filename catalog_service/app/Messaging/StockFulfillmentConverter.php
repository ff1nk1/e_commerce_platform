<?php

declare(strict_types=1);

namespace App\Messaging;

use Ecotone\Messaging\Attribute\MediaTypeConverter;
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;
use JsonException;

#[MediaTypeConverter]
final class StockFulfillmentConverter implements Converter
{
    /**
     * @param  mixed  $source
     */
    public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): StockFulfillment|string
    {
        if ($source instanceof StockFulfillment) {
            return json_encode(
                [
                    'sagaId' => $source->sagaId,
                    'steps' => $source->steps,
                ],
                JSON_THROW_ON_ERROR,
            );
        }

        throw_unless(is_string($source), JsonException::class, 'Stock fulfillment document must be a JSON string.');

        $data = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        throw_if(! is_array($data) || ! isset($data['sagaId']) || ! is_string($data['sagaId']), JsonException::class, 'Stock fulfillment document is missing sagaId.');

        $steps = is_array($data['steps'] ?? null) ? $data['steps'] : [];

        return StockFulfillment::restore($data['sagaId'], $steps);
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        $fulfillment = Type::object(StockFulfillment::class);
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
