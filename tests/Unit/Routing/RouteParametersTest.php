<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Routing;

use Flint\Http\Request;
use Flint\Routing\RouteParameters;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouteParametersTest extends TestCase
{
    public function testValuesArePreservedWithoutDecodingOrConversion(): void
    {
        $request = new Request(
            attributes: [
                'route.parameters' => [
                    'name' => 'Ada%20Lovelace',
                    'id' => '00042',
                    'encodedSlash' => 'a%2Fb',
                ],
            ],
        );

        $parameters = RouteParameters::fromRequest($request);

        self::assertTrue($parameters->has('name'));
        self::assertFalse($parameters->has('missing'));
        self::assertSame('Ada%20Lovelace', $parameters->get('name'));
        self::assertSame('00042', $parameters->get('id'));
        self::assertSame('a%2Fb', $parameters->get('encodedSlash'));
    }

    public function testMissingContextIsAnInternalError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'The request has no route parameter context.',
        );

        RouteParameters::fromRequest(new Request());
    }

    public function testMissingRequiredParameterIsAnInternalError(): void
    {
        $parameters = RouteParameters::fromRequest(
            new Request(
                attributes: ['route.parameters' => []],
            ),
        );

        self::assertFalse($parameters->has('name'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Required route parameter "name" is missing.',
        );

        $parameters->get('name');
    }

    public function testReplacingRequestContextDoesNotChangeExistingParameters(): void
    {
        $request = new Request(
            attributes: [
                'route.parameters' => ['name' => 'Ada'],
            ],
        );

        $first = RouteParameters::fromRequest($request);

        $changed = $request->withAttribute(
            'route.parameters',
            ['name' => 'Grace'],
        );

        $second = RouteParameters::fromRequest($changed);

        self::assertSame('Ada', $first->get('name'));
        self::assertSame('Grace', $second->get('name'));

        self::assertSame(
            ['name' => 'Ada'],
            $request->attribute('route.parameters'),
        );
    }

    #[DataProvider('invalidContexts')]
    public function testMalformedContextIsAnInternalError(mixed $context): void
    {
        $request = new Request(
            attributes: ['route.parameters' => $context],
        );

        $this->expectException(LogicException::class);

        RouteParameters::fromRequest($request);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidContexts(): iterable
    {
        yield 'null context' => [null];
        yield 'string context' => ['Ada'];
        yield 'numeric parameter name' => [['Ada']];
        yield 'empty parameter name' => [['' => 'Ada']];
        yield 'numeric parameter value' => [['id' => 42]];
        yield 'empty parameter value' => [['name' => '']];
    }
}
