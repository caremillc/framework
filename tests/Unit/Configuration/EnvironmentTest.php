<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Configuration;

use Flint\Configuration\Configuration;
use Flint\Configuration\Environment;
use Flint\Configuration\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    public function testEmptyStringIsPresent(): void
    {
        $environment = new Environment(['APP_NAME' => '']);

        self::assertTrue($environment->has('APP_NAME'));
        self::assertSame('', $environment->string('APP_NAME'));
        self::assertFalse($environment->has('MISSING'));
    }

    public function testMissingVariableThrows(): void
    {
        $environment = new Environment();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Environment variable "MISSING" is missing.',
        );

        $environment->string('MISSING');
    }

    public function testStringsArePreservedExactly(): void
    {
        $environment = new Environment([
            'APP_NAME' => ' Flint ',
        ]);

        self::assertSame(' Flint ', $environment->string('APP_NAME'));
        self::assertFalse($environment->has('app_name'));
    }

    public function testInputChangesDoNotChangeSnapshot(): void
    {
        $values = ['APP_NAME' => 'Flint'];
        $environment = new Environment($values);

        $values['APP_NAME'] = 'Changed';

        self::assertSame('Flint', $environment->string('APP_NAME'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function validBooleans(): iterable
    {
        yield 'true' => ['true', true];
        yield 'one' => ['1', true];
        yield 'false' => ['false', false];
        yield 'zero' => ['0', false];
    }

    #[DataProvider('validBooleans')]
    public function testBooleanParsing(
        string $value,
        bool $expected,
    ): void {
        $environment = new Environment(['APP_DEBUG' => $value]);

        self::assertSame($expected, $environment->boolean('APP_DEBUG'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBooleans(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['TRUE'];
        yield 'yes' => ['yes'];
        yield 'off' => ['off'];
        yield 'whitespace' => [' false '];
        yield 'other number' => ['2'];
    }

    #[DataProvider('invalidBooleans')]
    public function testInvalidBooleanIsRejected(string $value): void
    {
        $environment = new Environment(['APP_DEBUG' => $value]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Environment variable "APP_DEBUG" is not a valid boolean.',
        );

        $environment->boolean('APP_DEBUG');
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function validIntegers(): iterable
    {
        yield 'zero' => ['0', 0];
        yield 'positive' => ['3306', 3306];
        yield 'negative' => ['-12', -12];
        yield 'maximum' => [(string) PHP_INT_MAX, PHP_INT_MAX];
        yield 'minimum' => [(string) PHP_INT_MIN, PHP_INT_MIN];
    }

    #[DataProvider('validIntegers')]
    public function testIntegerParsing(
        string $value,
        int $expected,
    ): void {
        $environment = new Environment(['VALUE' => $value]);

        self::assertSame($expected, $environment->integer('VALUE'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIntegers(): iterable
    {
        yield 'empty' => [''];
        yield 'leading zero' => ['01'];
        yield 'positive sign' => ['+1'];
        yield 'negative zero' => ['-0'];
        yield 'decimal' => ['1.0'];
        yield 'exponent' => ['1e3'];
        yield 'whitespace' => [' 12 '];
        yield 'trailing newline' => ["12\n"];
        yield 'overflow' => [(string) PHP_INT_MAX . '0'];
        yield 'underflow' => [(string) PHP_INT_MIN . '0'];
    }

    #[DataProvider('invalidIntegers')]
    public function testInvalidIntegerIsRejected(string $value): void
    {
        $environment = new Environment(['VALUE' => $value]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Environment variable "VALUE" is not a valid integer.',
        );

        $environment->integer('VALUE');
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function invalidSnapshots(): iterable
    {
        yield 'empty name' => [['' => 'value']];
        yield 'integer name' => [[123 => 'value']];
        yield 'null value' => [['VALUE' => null]];
        yield 'boolean value' => [['VALUE' => false]];
        yield 'integer value' => [['VALUE' => 123]];
        yield 'array value' => [['VALUE' => []]];
    }

    /**
     * @param array<array-key, mixed> $values
     */
    #[DataProvider('invalidSnapshots')]
    public function testInvalidSnapshotIsRejected(array $values): void
    {
        $this->expectException(ConfigurationException::class);

        new Environment($values);
    }

    public function testParsingErrorDoesNotExposeValue(): void
    {
        $environment = new Environment([
            'APP_DEBUG' => 'secret-value-for-this-test',
        ]);

        try {
            $environment->boolean('APP_DEBUG');

            self::fail('Invalid boolean input must fail.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                'Environment variable "APP_DEBUG" is not a valid boolean.',
                $exception->getMessage(),
            );
        }
    }

    public function testParsedEnvironmentOverridesEarlierLayers(): void
    {
        $environment = new Environment([
            'APP_DEBUG' => 'false',
            'DATABASE_PORT' => '5432',
        ]);

        $configuration = Configuration::fromLayers(
            [
                'app.name' => 'Flint',
                'app.debug' => true,
                'database.port' => 3306,
            ],
            [
                'app.name' => 'Custom Flint',
                'database.port' => 3307,
            ],
            [
                'app.debug' => $environment->boolean('APP_DEBUG'),
                'database.port' => $environment->integer('DATABASE_PORT'),
            ],
        );

        self::assertSame(
            'Custom Flint',
            $configuration->string('app.name'),
        );
        self::assertFalse($configuration->boolean('app.debug'));
        self::assertSame(5432, $configuration->integer('database.port'));
    }

    public function testAbsentEnvironmentLeavesEarlierValueIntact(): void
    {
        $environment = new Environment();
        $overrides = [];

        if ($environment->has('APP_DEBUG')) {
            $overrides['app.debug'] = $environment->boolean('APP_DEBUG');
        }

        $configuration = Configuration::fromLayers(
            ['app.debug' => true],
            $overrides,
        );

        self::assertTrue($configuration->boolean('app.debug'));
    }
}
