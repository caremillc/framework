<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Application;

use Careminate\Application\ApplicationPaths;
use Careminate\Application\BootstrapInputs;
use Careminate\Application\Exception\InvalidApplicationInputException;
use Careminate\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BootstrapInputsTest extends TestCase
{
    public function testDefaultsAreProductionWithDebugDisabled(): void
    {
        $paths = $this->paths();
        $inputs = new BootstrapInputs($paths);

        self::assertSame($paths, $inputs->paths);
        self::assertSame('production', $inputs->environment);
        self::assertFalse($inputs->debug);
    }

    public function testEnvironmentDoesNotImplicitlyEnableDebug(): void
    {
        $inputs = new BootstrapInputs(
            $this->paths(),
            environment: 'development',
        );

        self::assertSame('development', $inputs->environment);
        self::assertFalse($inputs->debug);
    }

    public function testExplicitDebugChoiceIsPreserved(): void
    {
        $inputs = new BootstrapInputs(
            $this->paths(),
            environment: 'staging-eu_2',
            debug: true,
        );

        self::assertSame('staging-eu_2', $inputs->environment);
        self::assertTrue($inputs->debug);
    }

    public function testInputsCanBeRegisteredBeforeContainerFreeze(): void
    {
        $inputs = new BootstrapInputs($this->paths());

        $container = new Container();
        $container->register(BootstrapInputs::class, $inputs);
        $container->freeze();

        self::assertSame(
            $inputs,
            $container->get(BootstrapInputs::class),
        );
    }

    #[DataProvider('invalidEnvironments')]
    public function testInvalidEnvironmentNamesAreRejected(
        string $environment,
    ): void {
        $paths = $this->paths();

        $this->expectException(InvalidApplicationInputException::class);

        new BootstrapInputs($paths, $environment);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidEnvironments(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Production'];
        yield 'leading digit' => ['1production'];
        yield 'leading hyphen' => ['-production'];
        yield 'space' => ['production eu'];
        yield 'path' => ['production/eu'];
        yield 'null byte' => ["production\0"];
        yield 'newline' => ["production\n"];
        yield 'too long' => [str_repeat('a', 65)];
    }

    public function testMaximumEnvironmentLengthIsAccepted(): void
    {
        $environment = str_repeat('a', 64);
        $inputs = new BootstrapInputs($this->paths(), $environment);

        self::assertSame($environment, $inputs->environment);
    }

    private function paths(): ApplicationPaths
    {
        $directory = realpath(sys_get_temp_dir());

        self::assertIsString($directory);

        return new ApplicationPaths($directory);
    }
}
