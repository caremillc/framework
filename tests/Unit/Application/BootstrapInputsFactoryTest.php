<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Application;

use Careminate\Application\ApplicationPaths;
use Careminate\Application\BootstrapInputsFactory;
use Careminate\Application\Exception\InvalidApplicationInputException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class BootstrapInputsFactoryTest extends TestCase
{
    public function testMissingValuesUseExplicitDefaults(): void
    {
        $paths = $this->paths();

        $inputs = new BootstrapInputsFactory()->fromEnvironment($paths, []);

        self::assertSame($paths, $inputs->paths);
        self::assertSame('production', $inputs->environment);
        self::assertFalse($inputs->debug);
    }

    public function testDevelopmentDoesNotImplicitlyEnableDebug(): void
    {
        $inputs = new BootstrapInputsFactory()->fromEnvironment(
            $this->paths(),
            ['APP_ENV' => 'development'],
        );

        self::assertSame('development', $inputs->environment);
        self::assertFalse($inputs->debug);
    }

    #[DataProvider('debugValues')]
    public function testSupportedDebugValuesAreParsed(
        string $value,
        bool $expected,
    ): void {
        $inputs = new BootstrapInputsFactory()->fromEnvironment(
            $this->paths(),
            [
                'APP_ENV' => 'staging-eu_2',
                'APP_DEBUG' => $value,
            ],
        );

        self::assertSame('staging-eu_2', $inputs->environment);
        self::assertSame($expected, $inputs->debug);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function debugValues(): iterable
    {
        yield 'numeric enabled' => ['1', true];
        yield 'numeric disabled' => ['0', false];
        yield 'text enabled' => ['true', true];
        yield 'text disabled' => ['false', false];
    }

    #[DataProvider('invalidDebugValues')]
    public function testMalformedDebugValuesAreRejected(mixed $value): void
    {
        $paths = $this->paths();

        $this->expectException(InvalidApplicationInputException::class);
        $this->expectExceptionMessage(
            'APP_DEBUG must be "1", "0", "true" or "false".',
        );

        new BootstrapInputsFactory()->fromEnvironment(
            $paths,
            ['APP_DEBUG' => $value],
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidDebugValues(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['TRUE'];
        yield 'leading whitespace' => [' true'];
        yield 'trailing whitespace' => ['false '];
        yield 'newline' => ["true\n"];
        yield 'unsupported text' => ['yes'];
        yield 'unsupported number' => ['2'];
        yield 'null' => [null];
        yield 'boolean true' => [true];
        yield 'boolean false' => [false];
        yield 'integer' => [1];
        yield 'array' => [[]];
        yield 'object' => [new stdClass()];
    }

    #[DataProvider('invalidEnvironmentValues')]
    public function testMalformedEnvironmentNamesAreRejected(mixed $value): void
    {
        $paths = $this->paths();

        $this->expectException(InvalidApplicationInputException::class);

        new BootstrapInputsFactory()->fromEnvironment(
            $paths,
            ['APP_ENV' => $value],
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidEnvironmentValues(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Production'];
        yield 'whitespace' => [' production'];
        yield 'path' => ['../production'];
        yield 'null' => [null];
        yield 'boolean' => [false];
        yield 'integer' => [123];
        yield 'array' => [[]];
    }

    public function testUnrelatedValuesAreNotInterpreted(): void
    {
        $inputs = new BootstrapInputsFactory()->fromEnvironment(
            $this->paths(),
            [
                'DATABASE_PASSWORD' => new stdClass(),
                'UNRELATED' => null,
                'app_debug' => 'true',
            ],
        );

        self::assertSame('production', $inputs->environment);
        self::assertFalse($inputs->debug);
    }

    public function testChangingSnapshotDoesNotChangeCreatedInputs(): void
    {
        $factory = new BootstrapInputsFactory();
        $paths = $this->paths();

        $environment = [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '0',
        ];

        $original = $factory->fromEnvironment($paths, $environment);

        $environment['APP_ENV'] = 'development';
        $environment['APP_DEBUG'] = 'true';

        $updated = $factory->fromEnvironment($paths, $environment);

        self::assertSame('test', $original->environment);
        self::assertFalse($original->debug);
        self::assertSame('development', $updated->environment);
        self::assertTrue($updated->debug);
    }

    public function testSeparateCallsDoNotReuseEarlierValues(): void
    {
        $factory = new BootstrapInputsFactory();
        $paths = $this->paths();

        $first = $factory->fromEnvironment(
            $paths,
            ['APP_ENV' => 'development', 'APP_DEBUG' => 'true'],
        );
        $second = $factory->fromEnvironment($paths, []);

        self::assertSame('development', $first->environment);
        self::assertTrue($first->debug);
        self::assertSame('production', $second->environment);
        self::assertFalse($second->debug);
    }

    public function testInvalidDebugValueIsNotIncludedInExceptionMessage(): void
    {
        $paths = $this->paths();

        try {
            new BootstrapInputsFactory()->fromEnvironment(
                $paths,
                ['APP_DEBUG' => 'private-input-value'],
            );
        } catch (InvalidApplicationInputException $exception) {
            self::assertSame(
                'APP_DEBUG must be "1", "0", "true" or "false".',
                $exception->getMessage(),
            );

            return;
        }

        self::fail('Invalid debug input must be rejected.');
    }

    private function paths(): ApplicationPaths
    {
        $directory = realpath(sys_get_temp_dir());

        self::assertIsString($directory);

        return new ApplicationPaths($directory);
    }
}
