<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Closure;
use Flint\Container\Container;
use Flint\Container\Exception\EntryNotFoundException;
use Flint\Container\Exception\ResolutionException;
use Flint\Http\ContainerRequestHandler;
use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

final class ContainerRequestHandlerTest extends TestCase
{
    public function testResolutionIsLazyAndPreservesTransientBindings(): void
    {
        $container = new Container();
        $response = ResponseFactory::text('Injected dependency');
        $created = 0;

        $container->instance('response', $response);

        $container->bind(
            'handler',
            static function (ContainerInterface $services) use (
                &$created,
            ): RequestHandler {
                ++$created;

                $dependency = $services->get('response');

                if (!$dependency instanceof Response) {
                    throw new UnexpectedValueException(
                        'Expected a response dependency.',
                    );
                }

                return self::handler(
                    static fn (Request $request): Response => $dependency,
                );
            },
        );

        $adapter = new ContainerRequestHandler($container, 'handler');

        self::assertSame(0, $created);

        $first = $adapter->handle(new Request());
        $second = $adapter->handle(new Request());

        self::assertSame($response, $first);
        self::assertSame($response, $second);
        self::assertSame(2, $created);
    }

    public function testSingletonLifetimeBelongsToTheContainer(): void
    {
        $container = new Container();
        $created = 0;

        $container->singleton(
            'handler',
            static function (ContainerInterface $services) use (
                &$created,
            ): RequestHandler {
                ++$created;

                return self::handler(
                    static fn (Request $request): Response =>
                        ResponseFactory::text('Ready'),
                );
            },
        );

        $adapter = new ContainerRequestHandler($container, 'handler');

        $adapter->handle(new Request());
        $adapter->handle(new Request());

        self::assertSame(1, $created);
    }

    public function testResolvedServicesMustImplementRequestHandler(): void
    {
        $container = new Container();
        $container->instance('handler', new stdClass());

        $adapter = new ContainerRequestHandler($container, 'handler');

        $this->expectException(UnexpectedValueException::class);

        $adapter->handle(new Request());
    }

    public function testMissingServicesFailWhenInvoked(): void
    {
        $adapter = new ContainerRequestHandler(
            new Container(),
            'missing',
        );

        $this->expectException(EntryNotFoundException::class);

        $adapter->handle(new Request());
    }

    public function testResolutionFailureDoesNotPreventRetry(): void
    {
        $container = new Container();
        $attempts = 0;
        $failure = new RuntimeException('Construction failed.');

        $container->bind(
            'handler',
            static function (ContainerInterface $services) use (
                &$attempts,
                $failure,
            ): RequestHandler {
                ++$attempts;

                if ($attempts === 1) {
                    throw $failure;
                }

                return self::handler(
                    static fn (Request $request): Response =>
                        ResponseFactory::text('Recovered'),
                );
            },
        );

        $adapter = new ContainerRequestHandler($container, 'handler');

        try {
            $adapter->handle(new Request());
            self::fail('Expected construction to fail.');
        } catch (ResolutionException $exception) {
            self::assertSame($failure, $exception->getPrevious());
        }

        self::assertSame(
            'Recovered',
            $adapter->handle(new Request())->body(),
        );

        self::assertSame(2, $attempts);
    }

    public function testHandlerExceptionsPropagateUnchanged(): void
    {
        $container = new Container();
        $failure = new RuntimeException('Handler failed.');

        $container->instance(
            'handler',
            self::handler(
                static function (Request $request) use ($failure): Response {
                    throw $failure;
                },
            ),
        );

        $adapter = new ContainerRequestHandler($container, 'handler');

        try {
            $adapter->handle(new Request());
            self::fail('Expected the handler exception.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public function testEmptyServiceIdentifiersAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ContainerRequestHandler(new Container(), '');
    }

    /**
     * @param Closure(Request): Response $callback
     */
    private static function handler(Closure $callback): RequestHandler
    {
        return new class ($callback) implements RequestHandler {
            /**
             * @param Closure(Request): Response $callback
             */
            public function __construct(
                private readonly Closure $callback,
            ) {
            }

            public function handle(Request $request): Response
            {
                return ($this->callback)($request);
            }
        };
    }
}
