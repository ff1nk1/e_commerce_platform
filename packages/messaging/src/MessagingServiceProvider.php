<?php

declare(strict_types=1);

namespace Shop\Messaging;

use Illuminate\Support\ServiceProvider;
use Shop\Messaging\Ecotone\EcotoneInboxWriter;
use Shop\Messaging\Ecotone\EcotoneOutboxPublisher;
use Shop\Messaging\Ecotone\NullInboundEventFactory;

final class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EventCodec::class);
        $this->app->singleton(MessagePublisher::class, JungesKafkaPublisher::class);
        $this->app->singleton(OutboxPublisher::class, EcotoneOutboxPublisher::class);
        $this->app->singleton(InboxWriter::class, EcotoneInboxWriter::class);
        $this->app->singleton(KafkaInboundAdapter::class);
        $this->app->singleton(CatalogPayloadNormalizer::class);
        $this->app->singleton(InboundEventFactory::class, NullInboundEventFactory::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $namespaces = config('ecotone.namespaces', []);
        if (! is_array($namespaces)) {
            $namespaces = [];
        }
        if (! in_array('Shop\\Messaging', $namespaces, true)) {
            $namespaces[] = 'Shop\\Messaging';
        }

        config([
            'ecotone.namespaces' => array_values($namespaces),
            'ecotone.defaultErrorChannel' => config('ecotone.defaultErrorChannel', 'errorChannel'),
        ]);
    }
}
