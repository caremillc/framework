<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http;

use Careminate\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RequestRouteParametersTest extends TestCase
{
    public function testParametersAreInitiallyEmpty(): void
    {
        $request = new Request();

        self::assertSame([], $request->routeParameters());
        self::assertNull($request->routeParameter('id'));
    }

    public function testParametersCanBeAttachedImmutably(): void
    {
        $original = new Request();

        $updated = $original->withRouteParameters([
            'id' => '42',
        ]);

        self::assertSame([], $original->routeParameters());
        self::assertSame('42', $updated->routeParameter('id'));
    }

    public function testParametersSurviveBodyReplacement(): void
    {
        $request = new Request(
            'POST',
            '/users/42',
        );

        $updated = $request
            ->withRouteParameters(['id' => '42'])
            ->withBody('hello');

        self::assertSame('42', $updated->routeParameter('id'));
        self::assertSame('hello', $updated->body);
    }

    public function testParametersSurviveHeaderReplacement(): void
    {
        $request = new Request();

        $updated = $request
            ->withRouteParameters(['id' => '42'])
            ->withHeader('X-Test', 'yes');

        self::assertSame('42', $updated->routeParameter('id'));
        self::assertSame('yes', $updated->header('X-Test'));
    }

    public function testParametersSurviveMethodReplacement(): void
    {
        $request = new Request();

        $updated = $request
            ->withRouteParameters(['id' => '42'])
            ->withMethod('POST');

        self::assertSame('42', $updated->routeParameter('id'));
        self::assertSame('POST', $updated->method);
    }

    public function testRejectsNonStringParameterValues(): void
    {
        $request = new Request();

        $this->expectException(
            InvalidArgumentException::class,
        );

        $request->withRouteParameters([
            'id' => 42,
        ]);
    }
}
