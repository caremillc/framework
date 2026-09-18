<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container\Compilation;

use ArrayObject;
use Careminate\Container\Compilation\Exception\DefinitionException;
use Careminate\Container\Compilation\Exception\PortableValueException;
use Careminate\Container\Compilation\Internal\ArgumentInstruction;
use Careminate\Container\Compilation\Internal\ArgumentPlan;
use Careminate\Container\Container;
use Careminate\Container\Exception\EntryNotFoundException;
use CareminateIntegration\Tests\Fixtures\Container\PlannedDefaultsService;
use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;

final class ArgumentPlanTest extends TestCase
{
    public function testEmptyPlanProducesNoArguments(): void
    {
        $container = new Container();

        self::assertSame(
            [],
            new ArgumentPlan()->resolve($container, $container->tagged(...)),
        );
    }

    public function testLiteralNullIsSuppliedWhileDefaultIsOmitted(): void
    {
        $container = new Container();

        $plan = new ArgumentPlan(
            ArgumentInstruction::omitDefault('token'),
            ArgumentInstruction::literal('optional', null),
            ArgumentInstruction::literal('label', 'configured'),
        );

        $arguments = $plan->resolve($container, $container->tagged(...));

        self::assertSame(
            ['optional' => null, 'label' => 'configured'],
            $arguments,
        );

        $service = new PlannedDefaultsService(...$arguments);

        self::assertNull($service->optional);
        self::assertSame('configured', $service->label);
    }

    public function testObjectDefaultsAreCreatedIndependentlyByPhp(): void
    {
        $container = new Container();

        $plan = new ArgumentPlan(
            ArgumentInstruction::omitDefault('token'),
            ArgumentInstruction::omitDefault('optional'),
            ArgumentInstruction::literal('label', 'configured'),
        );

        $firstArguments = $plan->resolve(
            $container,
            $container->tagged(...),
        );

        self::assertSame(['label' => 'configured'], $firstArguments);

        $first = new PlannedDefaultsService(...$firstArguments);

        $secondArguments = $plan->resolve(
            $container,
            $container->tagged(...),
        );

        self::assertSame(['label' => 'configured'], $secondArguments);

        $second = new PlannedDefaultsService(...$secondArguments);

        self::assertNotSame($first->token, $second->token);
        self::assertSame('default', $first->optional);
        self::assertSame('default', $second->optional);
        self::assertSame('configured', $first->label);
        self::assertSame('configured', $second->label);
    }

    public function testOptionalServiceIsOmittedWhenAbsent(): void
    {
        $container = new Container();

        $plan = new ArgumentPlan(
            ArgumentInstruction::optionalService('optional', 'optional'),
        );

        $arguments = $plan->resolve($container, $container->tagged(...));

        self::assertSame([], $arguments);
        self::assertSame(
            'default',
            new PlannedDefaultsService(...$arguments)->optional,
        );
    }

    public function testRegisteredNullOverridesAnOptionalDefault(): void
    {
        $container = new Container();
        $container->register('optional', null);

        $plan = new ArgumentPlan(
            ArgumentInstruction::optionalService('optional', 'optional'),
        );

        $arguments = $plan->resolve($container, $container->tagged(...));

        self::assertSame(['optional' => null], $arguments);
        self::assertNull(new PlannedDefaultsService(...$arguments)->optional);
    }

    public function testOptionalPresenceIsCheckedOnEachExecution(): void
    {
        $container = new Container();

        $plan = new ArgumentPlan(
            ArgumentInstruction::optionalService('optional', 'optional'),
        );

        self::assertSame(
            [],
            $plan->resolve($container, $container->tagged(...)),
        );

        $container->register('optional', 'available');

        self::assertSame(
            ['optional' => 'available'],
            $plan->resolve($container, $container->tagged(...)),
        );
    }

    public function testServiceAliasResolvesToItsRegisteredInstance(): void
    {
        $container = new Container();
        $dependency = new stdClass();

        $container->register('dependency', $dependency);
        $container->alias('alias', 'dependency');

        $plan = new ArgumentPlan(
            ArgumentInstruction::service('dependency', 'alias'),
        );

        self::assertSame(
            ['dependency' => $dependency],
            $plan->resolve($container, $container->tagged(...)),
        );
    }

    public function testMissingRequiredServiceRetainsItsContainerException(): void
    {
        $container = new Container();

        $plan = new ArgumentPlan(
            ArgumentInstruction::service('dependency', 'missing'),
        );

        $this->expectException(EntryNotFoundException::class);

        $plan->resolve($container, $container->tagged(...));
    }

    public function testRequiredLookupDoesNotProbeHasAndPropagatesFailure(): void
    {
        $failure = new Error('Resolution failed.');
        $container = $this->createMock(ContainerInterface::class);

        $container->expects($this->never())->method('has');
        $container->expects($this->once())
            ->method('get')
            ->with('dependency')
            ->willThrowException($failure);

        $plan = new ArgumentPlan(
            ArgumentInstruction::service('dependency', 'dependency'),
        );

        $this->expectExceptionObject($failure);

        $plan->resolve(
            $container,
            static fn (string $tag): array => [],
        );
    }

    public function testInstructionsExecuteInTheirDeclaredOrder(): void
    {
        $container = new Container();

        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();

        $container->factory(
            'first',
            static function (ContainerInterface $resolver) use ($events): string {
                $events->append('first');

                return 'first value';
            },
        );

        $container->factory(
            'last',
            static function (ContainerInterface $resolver) use ($events): string {
                $events->append('last');

                return 'last value';
            },
        );

        $plan = new ArgumentPlan(
            ArgumentInstruction::service('firstParameter', 'first'),
            ArgumentInstruction::omitDefault('omittedParameter'),
            ArgumentInstruction::tagged('handlers', 'handlers'),
            ArgumentInstruction::optionalService('lastParameter', 'last'),
        );

        $arguments = $plan->resolve(
            $container,
            static function (string $tag) use ($events): array {
                $events->append($tag);

                return ['handler'];
            },
        );

        self::assertSame(
            ['first', 'handlers', 'last'],
            $events->getArrayCopy(),
        );

        self::assertSame(
            [
                'firstParameter' => 'first value',
                'handlers' => ['handler'],
                'lastParameter' => 'last value',
            ],
            $arguments,
        );
    }

    public function testTagsAreResolvedOnEachExecution(): void
    {
        $container = new Container();

        $plan = new ArgumentPlan(
            ArgumentInstruction::tagged('handlers', 'handlers'),
        );

        self::assertSame(
            ['handlers' => []],
            $plan->resolve($container, $container->tagged(...)),
        );

        $container->register('handler', 'added later');
        $container->tag('handlers', 'handler');

        self::assertSame(
            ['handlers' => ['added later']],
            $plan->resolve($container, $container->tagged(...)),
        );
    }

    public function testTaggedResolverFailureIsPropagated(): void
    {
        $container = new Container();
        $failure = new Error('Tag resolution failed.');

        $plan = new ArgumentPlan(
            ArgumentInstruction::tagged('handlers', 'handlers'),
        );

        $this->expectExceptionObject($failure);

        $plan->resolve(
            $container,
            static function (string $tag) use ($failure): never {
                throw $failure;
            },
        );
    }

    public function testLiteralSnapshotIsDetachedFromInputReferences(): void
    {
        $container = new Container();
        $shared = ['enabled' => true];

        $plan = new ArgumentPlan(
            ArgumentInstruction::literal(
                'settings',
                ['nested' => &$shared],
            ),
        );

        $shared['enabled'] = false;

        $first = $plan->resolve($container, $container->tagged(...));

        self::assertSame(
            ['settings' => ['nested' => ['enabled' => true]]],
            $first,
        );

        $first['settings'] = 'changed';

        self::assertSame(
            ['settings' => ['nested' => ['enabled' => true]]],
            $plan->resolve($container, $container->tagged(...)),
        );
    }

    public function testDuplicateParameterInstructionsAreRejected(): void
    {
        $this->expectException(DefinitionException::class);

        new ArgumentPlan(
            ArgumentInstruction::literal('parameter', null),
            ArgumentInstruction::omitDefault('parameter'),
        );
    }

    public function testObjectLiteralIsRejected(): void
    {
        $this->expectException(PortableValueException::class);

        ArgumentInstruction::literal('parameter', new stdClass());
    }

    #[DataProvider('invalidParameterNames')]
    public function testInvalidParameterNamesAreRejected(string $name): void
    {
        $this->expectException(DefinitionException::class);

        ArgumentInstruction::omitDefault($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidParameterNames(): iterable
    {
        yield 'empty' => [''];
        yield 'numeric' => ['0'];
        yield 'numeric prefix' => ['1parameter'];
        yield 'dollar prefix' => ['$parameter'];
        yield 'space' => ['two words'];
        yield 'null byte' => ["parameter\0"];
    }

    #[DataProvider('targetActions')]
    public function testEmptyTargetsAreRejected(string $action): void
    {
        $this->expectException(DefinitionException::class);

        match ($action) {
            'service' => ArgumentInstruction::service('parameter', ''),
            'tagged' => ArgumentInstruction::tagged('parameter', ''),
            'optional' => ArgumentInstruction::optionalService('parameter', ''),
            default => self::fail('Unknown test action.'),
        };
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function targetActions(): iterable
    {
        yield 'required service' => ['service'];
        yield 'tagged service' => ['tagged'];
        yield 'optional service' => ['optional'];
    }
}
