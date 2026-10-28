<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Concerns\UsesKafka;
use Tests\Concerns\UsesOpenSearch;
use Tests\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    use UsesKafka;
    use UsesOpenSearch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessOpenSearchAvailable();
        $this->recreateSearchIndices();
        $this->fakeKafka();
    }
}
