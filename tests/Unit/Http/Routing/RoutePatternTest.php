<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Routing;

use Careminate\Http\Routing\RoutePattern;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RoutePatternTest extends TestCase
{
    public function testStaticRouteMatches(): void
    {
        $pattern = new RoutePattern('/health');

        self::assertSame([], $pattern->match('/health'));
        self::assertNull($pattern->match('/missing'));
        self::assertFalse($pattern->isDynamic());
    }

    public function testRootRouteMatches(): void
    {
        $pattern = new RoutePattern('/');

        self::assertSame([], $pattern->match('/'));
        self::assertNull($pattern->match('/health'));
    }

    public function testSingleParameter(): void
    {
        $pattern = new RoutePattern('/users/{id}');

        self::assertSame(
            ['id' => '42'],
            $pattern->match('/users/42'),
        );

        self::assertTrue($pattern->isDynamic());
    }

    public function testMultipleParameters(): void
    {
        $pattern = new RoutePattern(
            '/projects/{projectId}/tasks/{taskId}',
        );

        self::assertSame(
            [
                'projectId' => '7',
                'taskId' => '19',
            ],
            $pattern->match('/projects/7/tasks/19'),
        );
    }

    public function testParameterCannotBeEmpty(): void
    {
        $pattern = new RoutePattern('/users/{id}');

        self::assertNull($pattern->match('/users/'));
    }

    public function testParameterCannotSpanSegments(): void
    {
        $pattern = new RoutePattern('/users/{id}');

        self::assertNull(
            $pattern->match('/users/42/edit'),
        );
    }

    public function testLiteralRegexCharactersAreEscaped(): void
    {
        $pattern = new RoutePattern('/files/report.json');

        self::assertSame(
            [],
            $pattern->match('/files/report.json'),
        );

        self::assertNull(
            $pattern->match('/files/reportXjson'),
        );
    }

    public function testRejectsDuplicateParameterNames(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new RoutePattern('/users/{id}/posts/{id}');
    }

    public function testRejectsInvalidParameterSyntax(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new RoutePattern('/users/{123}');
    }

    public function testRejectsRelativePattern(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new RoutePattern('users/{id}');
    }

    public function testRejectsMixedLiteralAndParameter(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new RoutePattern('/files/{name}.json');
    }
}
