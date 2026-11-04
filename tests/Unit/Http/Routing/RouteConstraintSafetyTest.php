<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Routing;

use Careminate\Http\Routing\RoutePattern;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RouteConstraintSafetyTest extends TestCase
{
    public function testAlternationMatchesWithinOneSegment(): void
    {
        $pattern = new RoutePattern(
            '/items/{id}',
            ['id' => '[0-9]+|[a-z]+'],
        );

        self::assertSame(
            ['id' => 'abc'],
            $pattern->match('/items/abc'),
        );

        self::assertSame(
            ['id' => '123'],
            $pattern->match('/items/123'),
        );
    }

    public function testAlternationCannotEscapeRouteBoundary(): void
    {
        $pattern = new RoutePattern(
            '/items/{id}',
            ['id' => '[0-9]+|[a-z]+'],
        );

        self::assertNull(
            $pattern->match('/items/abc/extra'),
        );
    }

    public function testWildcardCannotConsumeMultipleSegments(): void
    {
        $pattern = new RoutePattern(
            '/files/{name}',
            ['name' => '.*'],
        );

        self::assertNull(
            $pattern->match('/files/a/b'),
        );
    }

    public function testWildcardStillMatchesOneSegment(): void
    {
        $pattern = new RoutePattern(
            '/files/{name}',
            ['name' => '.*'],
        );

        self::assertSame(
            ['name' => 'report'],
            $pattern->match('/files/report'),
        );
    }

    public function testEmptyParameterIsRejected(): void
    {
        $pattern = new RoutePattern(
            '/files/{name}',
            ['name' => '.*'],
        );

        self::assertNull(
            $pattern->match('/files/'),
        );
    }

    public function testMalformedRegexIsRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new RoutePattern(
            '/items/{id}',
            ['id' => '[0-9'],
        );
    }

    public function testCapturingGroupsAreRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new RoutePattern(
            '/items/{id}',
            ['id' => '([0-9]+)'],
        );
    }

    public function testMultipleParametersRemainCorrectlyIndexed(): void
    {
        $pattern = new RoutePattern(
            '/projects/{project}/tasks/{task}',
            [
                'project' => '[0-9]+',
                'task' => '[a-z]+',
            ],
        );

        self::assertSame(
            [
                'project' => '42',
                'task' => 'review',
            ],
            $pattern->match(
                '/projects/42/tasks/review',
            ),
        );
    }

    public function testUnknownConstraintIsRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new RoutePattern(
            '/items/{id}',
            ['missing' => '[0-9]+'],
        );
    }
}
