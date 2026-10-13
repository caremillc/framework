<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Routing;

use Flint\Routing\RoutePattern;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouteGenerationTest extends TestCase
{
    public function testLiteralPatternGeneratesWithoutParameters(): void
    {
        self::assertSame(
            '/health',
            new RoutePattern('/health')->generate(),
        );

        self::assertSame(
            '/',
            new RoutePattern('/')->generate(),
        );
    }

    public function testParametersAreSubstitutedByName(): void
    {
        $pattern = new RoutePattern('/teams/{team}/users/{user}');

        self::assertSame(
            '/teams/core%20team/users/Ada',
            $pattern->generate([
                'user' => 'Ada',
                'team' => 'core team',
            ]),
        );
    }

    public function testValuesAreEncodedAsIndividualSegments(): void
    {
        $pattern = new RoutePattern('/files/{name}');

        self::assertSame(
            '/files/a%2Fb%3Fc%23d',
            $pattern->generate(['name' => 'a/b?c#d']),
        );

        self::assertSame(
            '/files/a%2Bb',
            $pattern->generate(['name' => 'a+b']),
        );

        self::assertSame(
            '/files/%252F',
            $pattern->generate(['name' => '%2F']),
        );

        $path = $pattern->generate(['name' => 'a/b']);

        self::assertSame(
            ['name' => 'a%2Fb'],
            $pattern->match($path),
        );
    }

    public function testMissingParametersAreRejected(): void
    {
        $pattern = new RoutePattern('/users/{id}');

        $this->expectException(InvalidArgumentException::class);

        $pattern->generate();
    }

    public function testUnexpectedParametersAreRejected(): void
    {
        $pattern = new RoutePattern('/users/{id}');

        $this->expectException(InvalidArgumentException::class);

        $pattern->generate([
            'id' => '42',
            'unused' => 'value',
        ]);
    }

    public function testEmptyValuesAreRejected(): void
    {
        $pattern = new RoutePattern('/users/{id}');

        $this->expectException(InvalidArgumentException::class);

        $pattern->generate(['id' => '']);
    }

    #[DataProvider('nonStringValues')]
    public function testNonStringValuesAreRejected(mixed $value): void
    {
        $pattern = new RoutePattern('/users/{id}');

        $this->expectException(InvalidArgumentException::class);

        $pattern->generate(['id' => $value]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonStringValues(): iterable
    {
        yield 'integer' => [42];
        yield 'boolean' => [false];
        yield 'null' => [null];
        yield 'array' => [[]];
    }

    #[DataProvider('dotSegments')]
    public function testDotSegmentsAreRejected(string $value): void
    {
        $pattern = new RoutePattern('/files/{name}');

        $this->expectException(InvalidArgumentException::class);

        $pattern->generate(['name' => $value]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dotSegments(): iterable
    {
        yield 'current directory' => ['.'];
        yield 'parent directory' => ['..'];
    }
}
