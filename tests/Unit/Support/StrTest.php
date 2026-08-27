<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Support;

use Careminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StrTest extends TestCase
{
    public function testBlankDetection(): void
    {
        self::assertTrue(Str::isBlank(''));
        self::assertTrue(Str::isBlank(" \t\n"));
        self::assertFalse(Str::isBlank('Caremi'));
    }

    public function testContainsStartsWithAndEndsWith(): void
    {
        $value = 'CareminateFramework';

        self::assertTrue(Str::contains($value, 'Framework'));
        self::assertTrue(Str::startsWith($value, 'Careminate'));
        self::assertTrue(Str::endsWith($value, 'Framework'));
        self::assertFalse(Str::contains($value, 'Database'));
    }

    #[DataProvider('caseConversionProvider')]
    public function testCaseConversions(
        string $input,
        string $snake,
        string $kebab,
        string $studly,
        string $camel,
    ): void {
        self::assertSame($snake, Str::snake($input));
        self::assertSame($kebab, Str::kebab($input));
        self::assertSame($studly, Str::studly($input));
        self::assertSame($camel, Str::camel($input));
    }

    /**
     * @return iterable<
     *     string,
     *     array{string, string, string, string, string}
     * >
     */
    public static function caseConversionProvider(): iterable
    {
        yield 'camel case' => [
            'databaseConnection',
            'database_connection',
            'database-connection',
            'DatabaseConnection',
            'databaseConnection',
        ];

        yield 'studly case' => [
            'DatabaseConnection',
            'database_connection',
            'database-connection',
            'DatabaseConnection',
            'databaseConnection',
        ];

        yield 'acronym' => [
            'HTTPKernel',
            'http_kernel',
            'http-kernel',
            'HttpKernel',
            'httpKernel',
        ];

        yield 'spaces and hyphens' => [
            'service-provider registry',
            'service_provider_registry',
            'service-provider-registry',
            'ServiceProviderRegistry',
            'serviceProviderRegistry',
        ];
    }

    public function testCaseConversionsPreserveEmptyValue(): void
    {
        self::assertSame('', Str::snake(''));
        self::assertSame('', Str::kebab(''));
        self::assertSame('', Str::studly(''));
        self::assertSame('', Str::camel(''));
    }
}
