<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Configuration;

use Flint\Configuration\Configuration;
use Flint\Configuration\Environment;
use Flint\Configuration\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigurationBoundaryTest extends TestCase
{
    public function testIntegerKeyIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Configuration keys must be non-empty strings.',
        );

        new Configuration([123 => 'value']);
    }

    public function testNumericStringConvertedToIntegerKeyIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Configuration keys must be non-empty strings.',
        );

        new Configuration(['123' => 'value']);
    }

    public function testReferencedInputCannotChangeStoredValue(): void
    {
        $name = 'Flint';
        $values = ['app.name' => &$name];

        $configuration = new Configuration($values);

        $name = 'Changed';

        self::assertSame('Flint', $configuration->string('app.name'));
    }

    public function testReferencedEnvironmentCannotChangeSnapshot(): void
    {
        $name = 'Flint';
        $values = ['APP_NAME' => &$name];

        $environment = new Environment($values);

        $name = 'Changed';

        self::assertSame('Flint', $environment->string('APP_NAME'));
    }

    public function testEmptyLayersPreserveEarlierValues(): void
    {
        $configuration = Configuration::fromLayers(
            ['app.name' => 'Flint'],
            [],
            [],
        );

        self::assertSame('Flint', $configuration->string('app.name'));
    }

    public function testFalsyOverridesArePreserved(): void
    {
        $configuration = Configuration::fromLayers(
            [
                'app.name' => 'Flint',
                'app.debug' => true,
                'retry.count' => 3,
                'optional' => 'original',
            ],
            [
                'app.name' => '',
                'app.debug' => false,
                'retry.count' => 0,
                'optional' => null,
            ],
        );

        self::assertSame('', $configuration->string('app.name'));
        self::assertFalse($configuration->boolean('app.debug'));
        self::assertSame(0, $configuration->integer('retry.count'));
        self::assertTrue($configuration->has('optional'));
        self::assertNull($configuration->get('optional'));
    }

    public function testResourceValueIsRejected(): void
    {
        $resource = fopen('php://memory', 'r+');

        if ($resource === false) {
            self::fail('Could not create the test resource.');
        }

        try {
            $this->expectException(ConfigurationException::class);
            $this->expectExceptionMessage(
                'Configuration key "stream" must contain a scalar value or null.',
            );

            new Configuration(['stream' => $resource]);
        } finally {
            fclose($resource);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function typedAccessors(): iterable
    {
        yield 'string' => ['string'];
        yield 'integer' => ['integer'];
        yield 'boolean' => ['boolean'];
        yield 'float' => ['float'];
    }

    #[DataProvider('typedAccessors')]
    public function testTypedAccessorPreservesMissingKeyDiagnostic(
        string $accessor,
    ): void {
        $configuration = new Configuration();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Configuration key "missing" is missing.',
        );

        match ($accessor) {
            'string' => $configuration->string('missing'),
            'integer' => $configuration->integer('missing'),
            'boolean' => $configuration->boolean('missing'),
            'float' => $configuration->float('missing'),
            default => self::fail('Unknown test accessor.'),
        };
    }

    public function testMissingBooleanEnvironmentVariableThrows(): void
    {
        $environment = new Environment();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Environment variable "APP_DEBUG" is missing.',
        );

        $environment->boolean('APP_DEBUG');
    }

    public function testMissingIntegerEnvironmentVariableThrows(): void
    {
        $environment = new Environment();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Environment variable "DATABASE_PORT" is missing.',
        );

        $environment->integer('DATABASE_PORT');
    }

    public function testInvalidEnvironmentDoesNotFallBackToDefault(): void
    {
        $environment = new Environment([
            'APP_DEBUG' => 'invalid',
        ]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Environment variable "APP_DEBUG" is not a valid boolean.',
        );

        Configuration::fromLayers(
            ['app.debug' => false],
            ['app.debug' => $environment->boolean('APP_DEBUG')],
        );
    }
}
