<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use ArrayObject;
use Careminate\Container\Container;
use Careminate\Container\Exception\ResolutionException;
use CareminateIntegration\Tests\Fixtures\Container\TaggedPipeline;
use Closure;
use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;

final class TaggedAutowireTest extends TestCase
{
    public function testTaggedArgumentsResolveInMembershipOrder(): void
    {
        $container = new Container();

        $container->register('first', 'one');
        $container->register('second', 'two');
        $container->tag('handlers', 'second', 'first');

        $container->autowire(
            'pipeline',
            TaggedPipeline::class,
            arguments: ['name' => 'custom'],
            taggedArguments: ['handlers' => 'handlers'],
        );

        $pipeline = $container->get('pipeline');

        self::assertInstanceOf(TaggedPipeline::class, $pipeline);
        self::assertSame(['two', 'one'], $pipeline->handlers);
        self::assertSame('custom', $pipeline->name);
    }

    public function testUnknownTagInjectsAnEmptyCollection(): void
    {
        $container = new Container();

        $container->autowire(
            'pipeline',
            TaggedPipeline::class,
            taggedArguments: ['handlers' => 'unknown'],
        );

        $pipeline = $container->get('pipeline');

        self::assertInstanceOf(TaggedPipeline::class, $pipeline);
        self::assertSame([], $pipeline->handlers);
    }

    public function testTagMembersCanBeRegisteredAfterAutowireRegistration(): void
    {
        $container = new Container();

        $container->autowire(
            'pipeline',
            TaggedPipeline::class,
            taggedArguments: ['handlers' => 'handlers'],
        );

        $container->register('handler', 'late');
        $container->tag('handlers', 'handler');

        $pipeline = $container->get('pipeline');

        self::assertInstanceOf(TaggedPipeline::class, $pipeline);
        self::assertSame(['late'], $pipeline->handlers);
    }

    public function testRegistrationDoesNotResolveTaggedFactories(): void
    {
        $container = new Container();

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $container->factory(
            'handler',
            static function (ContainerInterface $resolver) use ($calls): stdClass {
                $calls->append(true);

                return new stdClass();
            },
        );

        $container->tag('handlers', 'handler');

        $container->autowire(
            'pipeline',
            TaggedPipeline::class,
            taggedArguments: ['handlers' => 'handlers'],
        );

        self::assertCount(0, $calls);

        $first = $container->get('pipeline');
        $second = $container->get('pipeline');

        self::assertInstanceOf(TaggedPipeline::class, $first);
        self::assertInstanceOf(TaggedPipeline::class, $second);
        self::assertCount(1, $first->handlers);
        self::assertCount(1, $second->handlers);
        self::assertNotSame($first->handlers[0], $second->handlers[0]);
        self::assertCount(2, $calls);
    }

    public function testTransientPipelineSeesLaterTagMembership(): void
    {
        $container = new Container();

        $container->register('first', 'one');
        $container->register('second', 'two');
        $container->tag('handlers', 'first');

        $container->autowire(
            'pipeline',
            TaggedPipeline::class,
            taggedArguments: ['handlers' => 'handlers'],
        );

        $first = $container->get('pipeline');

        $container->tag('handlers', 'second');

        $second = $container->get('pipeline');

        self::assertInstanceOf(TaggedPipeline::class, $first);
        self::assertInstanceOf(TaggedPipeline::class, $second);
        self::assertSame(['one'], $first->handlers);
        self::assertSame(['one', 'two'], $second->handlers);
    }

    public function testSharedPipelineRetainsItsConstructedCollection(): void
    {
        $container = new Container();

        $container->register('first', 'one');
        $container->register('second', 'two');
        $container->tag('handlers', 'first');

        $container->autowire(
            'pipeline',
            TaggedPipeline::class,
            shared: true,
            taggedArguments: ['handlers' => 'handlers'],
        );

        $first = $container->get('pipeline');

        $container->tag('handlers', 'second');

        $second = $container->get('pipeline');

        self::assertInstanceOf(TaggedPipeline::class, $first);
        self::assertSame($first, $second);
        self::assertSame(['one'], $first->handlers);
        self::assertSame(['one', 'two'], $container->tagged('handlers'));
    }

    public function testLiteralArgumentsRemainLiteral(): void
    {
        $container = new Container();
        $literal = ['handlers'];

        $container->register('handler', 'resolved value');
        $container->tag('handlers', 'handler');

        $container->autowire(
            'pipeline',
            TaggedPipeline::class,
            arguments: ['handlers' => $literal],
        );

        $pipeline = $container->get('pipeline');

        self::assertInstanceOf(TaggedPipeline::class, $pipeline);
        self::assertSame($literal, $pipeline->handlers);
    }

    public function testConflictingArgumentStrategiesAreRejected(): void
    {
        $container = new Container();

        $failure = self::captureFailure(
            static function () use ($container): void {
                $container->autowire(
                    'pipeline',
                    TaggedPipeline::class,
                    arguments: ['handlers' => []],
                    taggedArguments: ['handlers' => 'handlers'],
                );
            },
        );

        self::assertInstanceOf(
            InvalidArgumentException::class,
            $failure->getPrevious(),
        );

        self::assertFalse($container->has('pipeline'));

        $container->register('pipeline', 'replacement');

        self::assertSame('replacement', $container->get('pipeline'));
    }

    /**
     * @param array<array-key, mixed> $mapping
     */
    #[DataProvider('invalidMappings')]
    public function testInvalidMappingsDoNotRegisterTheService(
        array $mapping,
    ): void {
        $container = new Container();

        $failure = self::captureFailure(
            static function () use ($container, $mapping): void {
                $container->autowire(
                    'pipeline',
                    TaggedPipeline::class,
                    taggedArguments: $mapping,
                );
            },
        );

        self::assertSame(
            'The autowired entry could not be registered.',
            $failure->getMessage(),
        );

        self::assertInstanceOf(
            InvalidArgumentException::class,
            $failure->getPrevious(),
        );

        self::assertFalse($container->has('pipeline'));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function invalidMappings(): iterable
    {
        yield 'empty tag' => [['handlers' => '']];
        yield 'non-string tag' => [['handlers' => 1]];

        yield 'unknown parameter' => [[
            'handlers' => 'handlers',
            'missing' => 'handlers',
        ]];

        yield 'non-array parameter' => [[
            'handlers' => 'handlers',
            'name' => 'handlers',
        ]];

        yield 'empty parameter name' => [[
            'handlers' => 'handlers',
            '' => 'handlers',
        ]];

        yield 'numeric parameter key' => [[
            'handlers' => 'handlers',
            0 => 'handlers',
        ]];
    }

    public function testTaggedFailurePreservesOwnerAndMemberPath(): void
    {
        $container = new Container();
        $original = new Error('Handler construction failed.');

        $container->factory(
            'handler',
            static function (ContainerInterface $resolver) use ($original): never {
                throw $original;
            },
        );

        $container->alias('handler.alias', 'handler');
        $container->tag('handlers', 'handler.alias');

        $container->autowire(
            'pipeline',
            TaggedPipeline::class,
            taggedArguments: ['handlers' => 'handlers'],
        );

        $failure = self::captureFailure(
            static fn (): mixed => $container->get('pipeline'),
        );

        self::assertSame(
            ['pipeline', 'handler.alias'],
            $failure->dependencyPath(),
        );

        $memberFailure = $failure->getPrevious();

        self::assertInstanceOf(ResolutionException::class, $memberFailure);
        self::assertSame($original, $memberFailure->getPrevious());
    }

    public function testSelfContainingTagDetectsACycleAndClearsState(): void
    {
        $container = new Container();

        $container->autowire(
            'pipeline',
            TaggedPipeline::class,
            taggedArguments: ['handlers' => 'handlers'],
        );

        $container->tag('handlers', 'pipeline');

        $failure = self::captureFailure(
            static fn (): mixed => $container->get('pipeline'),
        );

        self::assertSame(
            ['pipeline', 'pipeline'],
            $failure->dependencyPath(),
        );

        $container->autowire(
            'healthy',
            TaggedPipeline::class,
            taggedArguments: ['handlers' => 'empty'],
        );

        $healthy = $container->get('healthy');

        self::assertInstanceOf(TaggedPipeline::class, $healthy);
        self::assertSame([], $healthy->handlers);
    }

    /**
     * @param Closure(): mixed $operation
     */
    private static function captureFailure(
        Closure $operation,
    ): ResolutionException {
        try {
            $operation();
        } catch (ResolutionException $exception) {
            return $exception;
        }

        self::fail('The operation must throw a resolution exception.');
    }
}
