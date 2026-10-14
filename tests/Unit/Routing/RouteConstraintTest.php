<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Routing;

use Flint\Routing\RouteConstraint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouteConstraintTest extends TestCase
{
    #[DataProvider('values')]
    public function testMatching(
        RouteConstraint $constraint,
        string $value,
        bool $expected,
    ): void {
        self::assertSame($expected, $constraint->matches($value));
    }

    /**
     * @return iterable<string, array{RouteConstraint, string, bool}>
     */
    public static function values(): iterable
    {
        yield 'digits with leading zeros' => [
            RouteConstraint::Digits, '00123', true,
        ];

        yield 'negative number' => [
            RouteConstraint::Digits, '-1', false,
        ];

        yield 'decimal number' => [
            RouteConstraint::Digits, '12.5', false,
        ];

        yield 'encoded digit' => [
            RouteConstraint::Digits, '%31', false,
        ];

        yield 'slug' => [
            RouteConstraint::Slug, 'Ada-Lovelace', true,
        ];

        yield 'underscore' => [
            RouteConstraint::Slug, 'Ada_Lovelace', false,
        ];

        yield 'consecutive hyphens' => [
            RouteConstraint::Slug, 'Ada--Lovelace', false,
        ];

        yield 'encoded slash' => [
            RouteConstraint::Slug, 'a%2Fb', false,
        ];

        yield 'lowercase UUID shape' => [
            RouteConstraint::Uuid,
            '550e8400-e29b-41d4-a716-446655440000',
            true,
        ];

        yield 'uppercase UUID shape' => [
            RouteConstraint::Uuid,
            '550E8400-E29B-41D4-A716-446655440000',
            true,
        ];

        yield 'invalid UUID character' => [
            RouteConstraint::Uuid,
            '550e8400-e29b-41d4-a716-44665544000Z',
            false,
        ];

        yield 'invalid UUID length' => [
            RouteConstraint::Uuid,
            '550e8400-e29b-41d4-a716-44665544000',
            false,
        ];
    }
}
