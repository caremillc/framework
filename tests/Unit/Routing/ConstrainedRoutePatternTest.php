<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Routing;

use Flint\Routing\RouteConstraint;
use Flint\Routing\RoutePattern;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConstrainedRoutePatternTest extends TestCase
{
    #[DataProvider('policies')]
    public function testMatchingAndGenerationUseTheSameConstraint(
        RouteConstraint $constraint,
        string $valid,
        string $invalid,
    ): void {
        $pattern = new RoutePattern(
            '/values/{value}',
            ['value' => $constraint],
        );

        self::assertSame(
            ['value' => $valid],
            $pattern->match('/values/' . $valid),
        );

        self::assertSame(
            '/values/' . $valid,
            $pattern->generate(['value' => $valid]),
        );

        self::assertNull(
            $pattern->match('/values/' . $invalid),
        );

        $this->expectException(InvalidArgumentException::class);

        $pattern->generate(['value' => $invalid]);
    }

    /**
     * @return iterable<string, array{RouteConstraint, string, string}>
     */
    public static function policies(): iterable
    {
        yield 'digits' => [
            RouteConstraint::Digits,
            '00123',
            '-1',
        ];

        yield 'slug' => [
            RouteConstraint::Slug,
            'Ada-Lovelace',
            'Ada_Lovelace',
        ];

        yield 'UUID shape' => [
            RouteConstraint::Uuid,
            '550e8400-e29b-41d4-a716-446655440000',
            'not-a-uuid',
        ];
    }

    public function testUnknownParameterConstraintsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RoutePattern(
            '/items/{id}',
            ['missing' => RouteConstraint::Digits],
        );
    }

    #[DataProvider('invalidConstraints')]
    public function testConstraintValuesMustBeEnumCases(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RoutePattern('/items/{id}', ['id' => $value]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidConstraints(): iterable
    {
        yield 'string' => ['digits'];
        yield 'null' => [null];
        yield 'boolean' => [true];
    }

    public function testNumericConstraintKeysAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RoutePattern(
            '/items/{id}',
            [0 => RouteConstraint::Digits],
        );
    }

    public function testLiteralRoutesCannotHaveParameterConstraints(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RoutePattern(
            '/health',
            ['id' => RouteConstraint::Digits],
        );
    }

    public function testUnconstrainedParametersKeepTheirExistingBehavior(): void
    {
        $pattern = new RoutePattern('/values/{value}');

        self::assertSame(
            ['value' => 'a%2Fb'],
            $pattern->match('/values/a%2Fb'),
        );

        self::assertSame(
            '/values/a%2Fb',
            $pattern->generate(['value' => 'a/b']),
        );
    }
}
