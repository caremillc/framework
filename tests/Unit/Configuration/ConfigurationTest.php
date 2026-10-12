<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Configuration;

use Flint\Configuration\Configuration;
use Flint\Configuration\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ConfigurationTest extends TestCase
{
    public function testTypedAccessReturnsExactValues(): void
    {
        $configuration = new Configuration([
            'app.name' => 'Flint',
            'database.port' => 3306,
            'app.debug' => false,
            'request.timeout' => 2.5,
        ]);

        self::assertSame('Flint', $configuration->string('app.name'));
        self::assertSame(3306, $configuration->integer('database.port'));
        self::assertFalse($configuration->boolean('app.debug'));
        self::assertSame(2.5, $configuration->float('request.timeout'));
    }

    public function testNullIsPresentWhileMissingKeyIsAbsent(): void
    {
        $configuration = new Configuration([
            'optional' => null,
        ]);

        self::assertTrue($configuration->has('optional'));
        self::assertNull($configuration->get('optional'));
        self::assertFalse($configuration->has('missing'));
    }

    public function testMissingKeyThrows(): void
    {
        $configuration = new Configuration();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Configuration key "missing" is missing.',
        );

        $configuration->get('missing');
    }

    public function testLaterLayersOverrideWithoutLosingOtherKeys(): void
    {
        $configuration = Configuration::fromLayers(
            [
                'app.name' => 'Flint',
                'app.debug' => true,
                'database.host' => 'localhost',
            ],
            [
                'app.debug' => false,
                'database.host' => 'database.internal',
            ],
            [
                'database.host' => 'database.override',
            ],
        );

        self::assertSame('Flint', $configuration->string('app.name'));
        self::assertFalse($configuration->boolean('app.debug'));
        self::assertSame(
            'database.override',
            $configuration->string('database.host'),
        );
    }

    public function testNullOverridesAnEarlierValue(): void
    {
        $configuration = Configuration::fromLayers(
            ['optional' => 'original'],
            ['optional' => null],
        );

        self::assertTrue($configuration->has('optional'));
        self::assertNull($configuration->get('optional'));
    }

    public function testKeysAreLiteralAndCaseSensitive(): void
    {
        $configuration = new Configuration([
            'app.name' => 'Flint',
        ]);

        self::assertTrue($configuration->has('app.name'));
        self::assertFalse($configuration->has('app'));
        self::assertFalse($configuration->has('APP.NAME'));
    }

    public function testChangingInputDoesNotChangeConfiguration(): void
    {
        $values = ['app.name' => 'Flint'];
        $configuration = new Configuration($values);

        $values['app.name'] = 'Changed';

        self::assertSame('Flint', $configuration->string('app.name'));
    }

    public function testTypedAccessDoesNotCoerceNumericStrings(): void
    {
        $configuration = new Configuration([
            'database.port' => '3306',
        ]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Configuration key "database.port" must be a integer.',
        );

        $configuration->integer('database.port');
    }

    public function testTypedAccessDoesNotCoerceBooleanStrings(): void
    {
        $configuration = new Configuration([
            'app.debug' => 'false',
        ]);

        $this->expectException(ConfigurationException::class);

        $configuration->boolean('app.debug');
    }

    public function testFloatAccessDoesNotCoerceIntegers(): void
    {
        $configuration = new Configuration([
            'request.timeout' => 2,
        ]);

        $this->expectException(ConfigurationException::class);

        $configuration->float('request.timeout');
    }

    public function testNullDoesNotSatisfyStringAccess(): void
    {
        $configuration = new Configuration([
            'optional' => null,
        ]);

        $this->expectException(ConfigurationException::class);

        $configuration->string('optional');
    }

    public function testTypeErrorDoesNotExposeTheValue(): void
    {
        $configuration = new Configuration([
            'credential' => 'secret-value-for-this-test',
        ]);

        try {
            $configuration->integer('credential');

            self::fail('The incorrect type should be rejected.');
        } catch (ConfigurationException $exception) {
            self::assertStringNotContainsString(
                'secret-value-for-this-test',
                $exception->getMessage(),
            );
            self::assertStringContainsString(
                'credential',
                $exception->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unsupportedValues(): iterable
    {
        yield 'array' => [['nested' => true]];
        yield 'object' => [new stdClass()];
    }

    #[DataProvider('unsupportedValues')]
    public function testUnsupportedValuesAreRejected(mixed $value): void
    {
        $this->expectException(ConfigurationException::class);

        new Configuration(['invalid' => $value]);
    }

    public function testEmptyKeyIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Configuration keys must be non-empty strings.',
        );

        new Configuration(['' => 'value']);
    }

    public function testInvalidEarlierLayerCannotBeHiddenByOverride(): void
    {
        $this->expectException(ConfigurationException::class);

        Configuration::fromLayers(
            ['setting' => ['unsupported']],
            ['setting' => 'valid replacement'],
        );
    }

    public function testNoLayersProducesEmptyConfiguration(): void
    {
        $configuration = Configuration::fromLayers();

        self::assertFalse($configuration->has('anything'));
    }
}
