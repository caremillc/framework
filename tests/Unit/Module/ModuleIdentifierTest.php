<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Module\Exception\InvalidModuleDefinitionException;
use Careminate\Module\ModuleIdentifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleIdentifierTest extends TestCase
{
    #[DataProvider('validIdentifiers')]
    public function testValidIdentifiersArePreserved(string $value): void
    {
        $identifier = new ModuleIdentifier($value);

        self::assertSame($value, $identifier->value);
    }

    #[DataProvider('invalidIdentifiers')]
    public function testInvalidIdentifiersAreRejected(string $value): void
    {
        $this->expectException(InvalidModuleDefinitionException::class);

        new ModuleIdentifier($value);
    }

    public function testEqualityUsesTheIdentifierValue(): void
    {
        $first = new ModuleIdentifier('caremi.billing');
        $same = new ModuleIdentifier('caremi.billing');
        $different = new ModuleIdentifier('caremi.catalog');

        self::assertNotSame($first, $same);
        self::assertTrue($first->equals($same));
        self::assertTrue($same->equals($first));
        self::assertFalse($first->equals($different));
    }

    public function testInvalidValueIsNotIncludedInTheExceptionMessage(): void
    {
        $value = "private\nmodule";

        try {
            new ModuleIdentifier($value);
        } catch (InvalidModuleDefinitionException $exception) {
            self::assertStringNotContainsString(
                $value,
                $exception->getMessage(),
            );

            return;
        }

        self::fail('An invalid identifier must be rejected.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validIdentifiers(): iterable
    {
        yield 'single letter' => ['a'];
        yield 'simple name' => ['billing'];
        yield 'numeric suffix' => ['billing2'];
        yield 'qualified name' => ['caremi.billing'];
        yield 'hyphenated name' => ['user-profile'];
        yield 'mixed separators' => ['caremi.user-profile'];
        yield 'numeric segment' => ['caremi.2'];
        yield 'maximum length' => [str_repeat('a', 64)];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIdentifiers(): iterable
    {
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'uppercase' => ['Billing'];
        yield 'leading digit' => ['2billing'];
        yield 'leading dot' => ['.billing'];
        yield 'trailing dot' => ['billing.'];
        yield 'leading hyphen' => ['-billing'];
        yield 'trailing hyphen' => ['billing-'];
        yield 'repeated dot' => ['caremi..billing'];
        yield 'repeated hyphen' => ['user--profile'];
        yield 'adjacent separators' => ['caremi.-billing'];
        yield 'underscore' => ['user_profile'];
        yield 'leading whitespace' => [' billing'];
        yield 'trailing whitespace' => ['billing '];
        yield 'newline' => ["billing\n"];
        yield 'null byte' => ["billing\0"];
        yield 'forward slash' => ['caremi/billing'];
        yield 'backslash' => ['caremi\\billing'];
        yield 'non ASCII' => ['facturación'];
    }
}
