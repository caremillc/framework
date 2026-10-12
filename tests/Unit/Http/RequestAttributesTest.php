<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Flint\Http\Headers;
use Flint\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RequestAttributesTest extends TestCase
{
    public function testMissingAttributesUseTheSuppliedDefault(): void
    {
        $request = new Request();

        self::assertFalse($request->hasAttribute('request.id'));
        self::assertNull($request->attribute('request.id'));
        self::assertSame('fallback', $request->attribute('request.id', 'fallback'));
    }

    public function testNullAttributeIsPresent(): void
    {
        $request = new Request(attributes: ['result' => null]);

        self::assertTrue($request->hasAttribute('result'));
        self::assertNull($request->attribute('result', 'fallback'));
    }

    public function testAddingAnAttributePreservesTheHttpRequest(): void
    {
        $headers = new Headers()->with('Content-Type', 'text/plain');

        $original = new Request(
            method: 'POST',
            target: '/example?mode=one',
            headers: $headers,
            body: 'Payload',
        );

        $context = new stdClass();
        $updated = $original->withAttribute('context', $context);

        self::assertNotSame($original, $updated);
        self::assertFalse($original->hasAttribute('context'));
        self::assertSame($context, $updated->attribute('context'));
        self::assertSame('POST', $updated->method());
        self::assertSame('/example?mode=one', $updated->target());
        self::assertSame('/example', $updated->path());
        self::assertSame('mode=one', $updated->queryString());
        self::assertSame($headers, $updated->headers());
        self::assertSame('Payload', $updated->body());
    }

    public function testReplacingAndRemovingAttributesPreservesEarlierRequests(): void
    {
        $original = new Request(
            attributes: [
                'request.id' => 'first',
                'retained' => false,
            ],
        );

        $replaced = $original->withAttribute('request.id', 'second');
        $removed = $replaced->withoutAttribute('request.id');
        $missingRemoved = $removed->withoutAttribute('missing');

        self::assertSame('first', $original->attribute('request.id'));
        self::assertSame('second', $replaced->attribute('request.id'));
        self::assertFalse($removed->hasAttribute('request.id'));
        self::assertFalse($removed->attribute('retained'));
        self::assertFalse($missingRemoved->hasAttribute('missing'));
        self::assertTrue($missingRemoved->hasAttribute('retained'));
    }

    public function testConstructorRejectsAnEmptyAttributeName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Request(attributes: ['' => 'invalid']);
    }

    public function testAttributeOperationsRejectEmptyNames(): void
    {
        $request = new Request();
        $rejections = 0;

        $operations = [
            static fn (): bool => $request->hasAttribute(''),
            static fn (): mixed => $request->attribute(''),
            static fn (): Request => $request->withAttribute('', 'value'),
            static fn (): Request => $request->withoutAttribute(''),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
            } catch (InvalidArgumentException) {
                ++$rejections;
            }
        }

        self::assertSame(4, $rejections);
    }
}
