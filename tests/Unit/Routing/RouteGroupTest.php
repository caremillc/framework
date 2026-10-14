<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Routing;

use Closure;
use Flint\Http\Middleware;
use Flint\Http\MiddlewarePipeline;
use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;
use Flint\Routing\Router;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouteGroupTest extends TestCase
{
    public function testNestedPrefixesAndNamesAreCombined(): void
    {
        $router = new Router();

        $v1 = $router
            ->group('/api', 'api.')
            ->group('/v1', 'v1.');

        $v1->addHandler(
            'GET',
            '/health',
            self::textHandler('Ready'),
            'health',
        );

        self::assertSame(
            'Ready',
            $router->handle(new Request('GET', '/api/v1/health'))->body(),
        );

        self::assertSame(
            '/api/v1/health',
            $router->path('api.v1.health'),
        );

        self::assertSame(
            '/api/v1/health?source=a%20b',
            $router->target(
                'api.v1.health',
                query: ['source' => 'a b'],
            ),
        );
    }

    public function testMiddlewareOrderAndRepeatedRequests(): void
    {
        $router = new Router();

        $outer = $router->group(
            '/api',
            'api.',
            self::mark('outer'),
        );

        $inner = $outer->group(
            '/v1',
            'v1.',
            self::mark('inner'),
        );

        $inner->addHandler(
            'GET',
            '/trace',
            self::traceHandler(),
            'trace',
            self::mark('route'),
        );

        $application = new MiddlewarePipeline(
            $router,
            self::mark('global'),
        );

        $request = new Request('GET', '/api/v1/trace');

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            self::assertSame(
                'global/outer/inner/route/|route|inner|outer|global',
                $application->handle($request)->body(),
            );

            self::assertFalse(
                $request->hasAttribute('group.trace'),
            );
        }
    }

    public function testSiblingGroupsDoNotShareChildMiddleware(): void
    {
        $router = new Router();

        $parent = $router->group(
            '/api',
            'api.',
            self::mark('parent'),
        );

        $first = $parent->group(
            '/first',
            'first.',
            self::mark('first'),
        );

        $second = $parent->group('/second', 'second.');

        $first->addHandler('GET', '/trace', self::traceHandler());
        $second->addHandler('GET', '/trace', self::traceHandler());

        self::assertSame(
            'parent/first/|first|parent',
            $router->handle(new Request('GET', '/api/first/trace'))->body(),
        );

        self::assertSame(
            'parent/|parent',
            $router->handle(new Request('GET', '/api/second/trace'))->body(),
        );
    }

    public function testParametersInPrefixesParticipateInMatchingAndGeneration(): void
    {
        $router = new Router();

        $members = $router
            ->group('/teams/{team}', 'teams.')
            ->group('/members', 'members.');

        $members->addHandler(
            'GET',
            '/{member}',
            self::handler(
                static fn (Request $request): Response =>
                    ResponseFactory::json(
                        $request->attribute('route.parameters'),
                    ),
            ),
            'show',
        );

        self::assertSame(
            '{"team":"engineering","member":"Ada"}',
            $router->handle(
                new Request('GET', '/teams/engineering/members/Ada'),
            )->body(),
        );

        self::assertSame(
            '/teams/platform%20team/members/Ada',
            $router->path(
                'teams.members.show',
                [
                    'team' => 'platform team',
                    'member' => 'Ada',
                ],
            ),
        );
    }

    public function testHeadFallbackAndMethodRejectionArePreserved(): void
    {
        $router = new Router();

        $router->group('/api', 'api.')->addHandler(
            'GET',
            '/health',
            self::handler(
                static fn (Request $request): Response =>
                    ResponseFactory::text($request->method()),
            ),
            'health',
        );

        $head = $router->handle(new Request('HEAD', '/api/health'));
        $post = $router->handle(new Request('POST', '/api/health'));

        self::assertSame(200, $head->status());
        self::assertSame('HEAD', $head->body());

        self::assertSame(405, $post->status());

        self::assertSame(
            ['GET, HEAD'],
            $post->headers()->get('Allow'),
        );
    }

    public function testDuplicateRegistrationPreservesTheOriginalRoute(): void
    {
        $router = new Router();
        $group = $router->group('/api', 'api.');

        $group->addHandler(
            'GET',
            '/health',
            self::textHandler('Original'),
            'health',
        );

        try {
            $group->addHandler(
                'GET',
                '/health',
                self::textHandler('Replacement'),
                'replacement',
            );

            self::fail('Expected duplicate registration to fail.');
        } catch (InvalidArgumentException) {
            self::assertSame(
                'Original',
                $router->handle(new Request('GET', '/api/health'))->body(),
            );
        }
    }

    public function testUnnamedRoutesRemainUnnamed(): void
    {
        $router = new Router();

        $router->group('/api', 'api.')->addHandler(
            'GET',
            '/health',
            self::textHandler('Ready'),
        );

        self::assertSame(
            200,
            $router->handle(new Request('GET', '/api/health'))->status(),
        );

        $this->expectException(InvalidArgumentException::class);

        $router->path('api.');
    }

    #[DataProvider('invalidPrefixes')]
    public function testInvalidLocalPrefixesAreRejected(string $prefix): void
    {
        $router = new Router();
        $parent = $router->group('/parent');

        $this->expectException(InvalidArgumentException::class);

        $parent->group($prefix);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPrefixes(): iterable
    {
        yield 'relative' => ['api'];
        yield 'trailing slash' => ['/api/'];
        yield 'query string' => ['/api?probe=1'];
        yield 'fragment' => ['/api#fragment'];
        yield 'invalid parameter' => ['/api/{bad-name}'];
        yield 'root slash' => ['/'];
    }

    #[DataProvider('invalidNamePrefixes')]
    public function testInvalidLocalNamePrefixesAreRejected(
        string $namePrefix,
    ): void {
        $router = new Router();
        $parent = $router->group('/parent', 'parent.');

        $this->expectException(InvalidArgumentException::class);

        $parent->group('/child', $namePrefix);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNamePrefixes(): iterable
    {
        yield 'leading digit' => ['1api.'];
        yield 'space' => ['api space.'];
        yield 'slash' => ['api/'];
    }

    public function testRelativeRoutePathsAreRejected(): void
    {
        $router = new Router();
        $group = $router->group('/api');

        $this->expectException(InvalidArgumentException::class);

        $group->addHandler(
            'GET',
            'health',
            self::textHandler('Ready'),
        );
    }

    public function testDuplicateParametersAcrossPrefixAndRouteAreRejected(): void
    {
        $router = new Router();
        $group = $router->group('/teams/{id}');

        try {
            $group->addHandler(
                'GET',
                '/members/{id}',
                self::textHandler('Unexpected'),
            );

            self::fail('Expected duplicate parameter names to fail.');
        } catch (InvalidArgumentException) {
            self::assertSame(
                404,
                $router->handle(
                    new Request('GET', '/teams/one/members/two'),
                )->status(),
            );
        }
    }

    private static function textHandler(string $body): RequestHandler
    {
        return self::handler(
            static fn (Request $request): Response =>
                ResponseFactory::text($body),
        );
    }

    private static function traceHandler(): RequestHandler
    {
        return self::handler(
            static function (Request $request): Response {
                $trace = $request->attribute('group.trace', '');

                if (!is_string($trace)) {
                    throw new LogicException('Expected a string trace.');
                }

                return ResponseFactory::text($trace);
            },
        );
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

    private static function mark(string $label): Middleware
    {
        return new class ($label) implements Middleware {
            public function __construct(
                private readonly string $label,
            ) {
            }

            public function process(
                Request $request,
                RequestHandler $next,
            ): Response {
                $trace = $request->attribute('group.trace', '');

                if (!is_string($trace)) {
                    throw new LogicException('Expected a string trace.');
                }

                $response = $next->handle(
                    $request->withAttribute(
                        'group.trace',
                        $trace . $this->label . '/',
                    ),
                );

                return $response->withBody(
                    $response->body() . '|' . $this->label,
                );
            }
        };
    }
}
