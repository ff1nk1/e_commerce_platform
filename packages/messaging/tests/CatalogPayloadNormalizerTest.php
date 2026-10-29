<?php

declare(strict_types=1);

namespace Shop\Messaging\Tests;

use PHPUnit\Framework\TestCase;
use Shop\Messaging\CatalogPayloadNormalizer;

final class CatalogPayloadNormalizerTest extends TestCase
{
    public function test_truncates_text_drops_bad_urls_and_caps_attributes(): void
    {
        $attributes = [];
        for ($i = 0; $i < 60; $i++) {
            $attributes[] = ['name' => str_repeat('n', 80).$i, 'value' => str_repeat('v', 80)];
        }

        $normalized = (new CatalogPayloadNormalizer)->normalize([
            'name' => str_repeat('a', 600),
            'description' => str_repeat('b', 6000),
            'image_url' => 'javascript:alert(1)',
            'attributes' => $attributes,
        ]);

        $this->assertSame(500, mb_strlen($normalized['name']));
        $this->assertSame(5000, mb_strlen($normalized['description']));
        $this->assertNull($normalized['image_url']);
        $this->assertCount(50, $normalized['attributes']);
        $this->assertSame(64, mb_strlen($normalized['attributes'][0]['name']));
        $this->assertSame(64, mb_strlen($normalized['attributes'][0]['value']));
    }

    public function test_keeps_http_image_url(): void
    {
        $normalized = (new CatalogPayloadNormalizer)->normalize([
            'image_url' => 'https://cdn.example/a.png',
        ]);

        $this->assertSame('https://cdn.example/a.png', $normalized['image_url']);
    }
}
