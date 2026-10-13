<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Routing;

use Flint\Routing\RoutePattern;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoutePatternTest extends TestCase
{
    public function testLiteralPathsMatchExactly(): void
    {
        $pattern = new RoutePattern('/health');

        self::assertTrue($pattern->isLiteral());
        self::assertSame([], $pattern->match('/health'));
        self::assertNull($pattern->match('/health/'));
        self::assertNull($pattern->match('/Health'));
    }

    public function testRootPathMatches(): void
    {
        $pattern = new RoutePattern('/');

        self::assertSame([], $pattern->match('/'));
        self::assertNull($pattern->match('/other'));
    }

    public function testParametersAreCapturedByName(): void
    {
        $pattern = new RoutePattern('/teams/{team}/users/{user}');

        self::assertFalse($pattern->isLiteral());
        self::assertSame(
            ['team' => 'engineering', 'user' => '42'],
            $pattern->match('/teams/engineering/users/42'),
        );
    }

    public function testParametersConsumeExactlyOneNonEmptySegment(): void
    {
        $pattern = new RoutePattern('/users/{id}');

        self::assertSame(['id' => '42'], $pattern->match('/users/42'));
        self::assertNull($pattern->match('/users/'));
        self::assertNull($pattern->match('/users/42/details'));
        self::assertNull($pattern->match('/users/42/'));
        self::assertNull($pattern->match('/Users/42'));
    }

    public function testCapturedValuesRemainEncoded(): void
    {
        $pattern = new RoutePattern('/files/{name}');

        self::assertSame(
            ['name' => 'a%2Fb'],
            $pattern->match('/files/a%2Fb'),
        );
        self::assertSame(
            ['name' => '%252F'],
            $pattern->match('/files/%252F'),
        );
        self::assertSame(
            ['name' => 'a+b'],
            $pattern->match('/files/a+b'),
        );
    }

    public function testLiteralRegexCharactersHaveNoSpecialMeaning(): void
    {
        $pattern = new RoutePattern('/v1.0/files/{name}');

        self::assertSame(
            ['name' => 'report'],
            $pattern->match('/v1.0/files/report'),
        );
        self::assertNull($pattern->match('/v1x0/files/report'));
    }

    public function testMatcherRejectsAQueryString(): void
    {
        $pattern = new RoutePattern('/users/{id}');

        $this->expectException(InvalidArgumentException::class);

        $pattern->match('/users/42?active=true');
    }

    #[DataProvider('invalidPatterns')]
    public function testInvalidPatternsAreRejected(string $pattern): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RoutePattern($pattern);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPatterns(): iterable
    {
        yield 'missing leading slash' => ['users/{id}'];
        yield 'empty name' => ['/users/{}'];
        yield 'numeric first character' => ['/users/{1id}'];
        yield 'partial segment' => ['/users/user-{id}'];
        yield 'unclosed parameter' => ['/users/{id'];
        yield 'duplicate name' => ['/users/{id}/posts/{id}'];
        yield 'query string' => ['/users/{id}?active=true'];
        yield 'fragment' => ['/users/{id}#details'];
        yield 'invalid percent escape' => ['/users/%ZZ'];
    }
}
