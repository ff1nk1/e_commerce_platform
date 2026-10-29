<?php

declare(strict_types=1);

namespace Shop\Messaging;

interface InboxWriter
{
    public function store(InboundEnvelope $envelope): void;
}
