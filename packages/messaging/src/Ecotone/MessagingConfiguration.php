<?php

declare(strict_types=1);

namespace Shop\Messaging\Ecotone;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Laravel\Config\LaravelConnectionReference;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Handler\Recoverability\ErrorHandlerConfiguration;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;

final class MessagingConfiguration
{
    #[ServiceContext]
    public function laravelConnection(): LaravelConnectionReference
    {
        return LaravelConnectionReference::defaultConnection(
            (string) config('database.default', 'sqlite'),
        );
    }

    /**
     * @return list<DbalBackedMessageChannelBuilder>
     */
    #[ServiceContext]
    public function channels(): array
    {
        return [
            DbalBackedMessageChannelBuilder::create('kafka-outbox'),
            DbalBackedMessageChannelBuilder::create('inbox'),
        ];
    }

    #[ServiceContext]
    public function dbal(): DbalConfiguration
    {
        $configuration = DbalConfiguration::createWithDefaults()
            ->withoutTransactionOnAsynchronousEndpoints(['kafka-outbox']);

        $aggregates = config('messaging.document_store_aggregates', []);
        if (is_array($aggregates) && $aggregates !== []) {
            $configuration = $configuration->withDocumentStore(
                enableDocumentStoreStandardRepository: true,
                documentStoreRelatedAggregates: array_values($aggregates),
            );
        }

        return $configuration;
    }

    #[ServiceContext]
    public function errorConfiguration(): ErrorHandlerConfiguration
    {
        return ErrorHandlerConfiguration::createWithDeadLetterChannel(
            'errorChannel',
            RetryTemplateBuilder::exponentialBackoff(1000, 10)->maxRetryAttempts(3),
            'dbal_dead_letter',
        );
    }
}
