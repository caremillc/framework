<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Config;

use Careminate\Config\Configuration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    public function testNestedKeyLookup(): void
    {
        $config = new Configuration([
            'app' => ['name' => 'Careminate'],
        ]);

        self::assertSame('Careminate', $config->get('app.name'));
    }

    public function testMissingKeyReturnsDefault(): void
    {
        $config = new Configuration();

        self::assertSame('fallback', $config->get('app.name', 'fallback'));
    }

    public function testNullIsAConfiguredValue(): void
    {
        $config = new Configuration([
            'app' => ['optional' => null],
        ]);

        self::assertTrue($config->has('app.optional'));
        self::assertNull($config->required('app.optional'));
    }

    public function testMissingRequiredKeyFails(): void
    {
        $config = new Configuration();

        $this->expectException(InvalidArgumentException::class);

        $config->required('app.name');
    }

    public function testTypedAccess(): void
    {
        $config = new Configuration([
            'app' => [
                'name' => 'Careminate',
                'debug' => false,
                'port' => 8080,
                'features' => ['routing'],
            ],
        ]);

        self::assertSame('Careminate', $config->string('app.name'));
        self::assertFalse($config->boolean('app.debug'));
        self::assertSame(8080, $config->integer('app.port'));
        self::assertSame(['routing'], $config->array('app.features'));
    }

    public function testInvalidTypeFails(): void
    {
        $config = new Configuration([
            'app' => ['debug' => 'false'],
        ]);

        $this->expectException(InvalidArgumentException::class);

        $config->boolean('app.debug');
    }

    public function testUnknownNestedKeyIsAbsent(): void
    {
        $config = new Configuration([
            'app' => ['name' => 'Careminate'],
        ]);

        self::assertFalse($config->has('app.missing'));
    }

    public function testEmptyKeyIsAbsent(): void
    {
        $config = new Configuration();

        self::assertFalse($config->has(''));
    }
}
