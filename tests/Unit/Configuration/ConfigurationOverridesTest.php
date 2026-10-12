<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Configuration;

use Flint\Configuration\Configuration;
use Flint\Configuration\Exception\ConfigurationException;
use PHPUnit\Framework\TestCase;

final class ConfigurationOverridesTest extends TestCase
{
    public function testOverridesCreateANewConfiguration(): void
    {
        $original = new Configuration([
            'app.name' => 'Flint',
            'app.debug' => false,
        ]);

        $updated = $original->withOverrides([
            'app.name' => 'Flint Local',
            'database.port' => 5432,
        ]);

        self::assertNotSame($original, $updated);

        self::assertSame('Flint', $original->string('app.name'));
        self::assertFalse($original->has('database.port'));

        self::assertSame('Flint Local', $updated->string('app.name'));
        self::assertFalse($updated->boolean('app.debug'));
        self::assertSame(5432, $updated->integer('database.port'));
    }

    public function testExplicitNullOverridesAnExistingValue(): void
    {
        $original = new Configuration([
            'optional' => 'original',
        ]);

        $updated = $original->withOverrides([
            'optional' => null,
        ]);

        self::assertSame('original', $original->string('optional'));
        self::assertTrue($updated->has('optional'));
        self::assertNull($updated->get('optional'));
    }

    public function testEmptyOverridesPreserveValues(): void
    {
        $original = new Configuration([
            'app.name' => 'Flint',
        ]);

        $updated = $original->withOverrides([]);

        self::assertNotSame($original, $updated);
        self::assertSame('Flint', $updated->string('app.name'));
    }

    public function testInvalidOverridesLeaveOriginalUnchanged(): void
    {
        $original = new Configuration([
            'app.name' => 'Flint',
        ]);

        try {
            $original->withOverrides([
                'app.name' => ['invalid'],
            ]);

            self::fail('Array values must be rejected.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                'Configuration key "app.name" must contain a scalar value or null.',
                $exception->getMessage(),
            );
        }

        self::assertSame('Flint', $original->string('app.name'));
    }
}
