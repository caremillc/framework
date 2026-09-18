<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container\Compilation;

use Careminate\Container\Compilation\DefinitionBuilder;
use Careminate\Container\Compilation\Exception\DefinitionException;
use Careminate\Container\Compilation\ServiceDefinition;
use Closure;
use PHPUnit\Framework\TestCase;

final class DefinitionBuilderTest extends TestCase
{
    public function testEmptyBuilderProducesAnEmptySnapshot(): void
    {
        $set = new DefinitionBuilder()->build();

        self::assertSame([], $set->services);
        self::assertSame([], $set->aliases);
        self::assertSame([], $set->tags);
        self::assertSame([], $set->contexts);
    }

    public function testForwardAliasesAreFlattenedInDeclarationOrder(): void
    {
        $builder = new DefinitionBuilder();

        $builder->alias('outer', 'inner');
        $builder->alias('inner', 'service');

        $service = ServiceDefinition::forValue('service', null);
        $builder->add($service);

        $set = $builder->build();

        self::assertSame([$service], $set->services);
        self::assertSame(
            [
                ['alias' => 'outer', 'target' => 'service'],
                ['alias' => 'inner', 'target' => 'service'],
            ],
            $set->aliases,
        );
    }

    public function testDuplicateServiceCannotReplaceTheOriginal(): void
    {
        $builder = new DefinitionBuilder();
        $original = ServiceDefinition::forValue('service', null);

        $builder->add($original);

        self::captureFailure(
            static fn () => $builder->add(
                ServiceDefinition::forValue('service', 'replacement'),
            ),
        );

        self::assertSame([$original], $builder->build()->services);
    }

    public function testServicesAndAliasesShareOneIdentifierNamespace(): void
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forValue('service', null));

        self::captureFailure(
            static fn () => $builder->alias('service', 'other'),
        );

        $builder->alias('alias', 'service');

        self::captureFailure(
            static fn () => $builder->add(
                ServiceDefinition::forValue('alias', null),
            ),
        );

        self::captureFailure(
            static fn () => $builder->alias('alias', 'service'),
        );

        self::assertSame(
            [['alias' => 'alias', 'target' => 'service']],
            $builder->build()->aliases,
        );
    }

    public function testMissingAliasTargetCanBeAddedAfterFailedBuild(): void
    {
        $builder = new DefinitionBuilder();

        $builder->alias('alias', 'missing');

        self::captureFailure(static fn () => $builder->build());

        $builder->add(ServiceDefinition::forValue('missing', 42));

        self::assertSame(
            [['alias' => 'alias', 'target' => 'missing']],
            $builder->build()->aliases,
        );
    }

    public function testSelfReferencingAliasIsRejected(): void
    {
        $builder = new DefinitionBuilder();

        $builder->alias('self', 'self');

        $failure = self::captureFailure(static fn () => $builder->build());

        self::assertSame(
            'The definitions contain a circular alias chain.',
            $failure->getMessage(),
        );
    }

    public function testMultiAliasCycleIsRejected(): void
    {
        $builder = new DefinitionBuilder();

        $builder->alias('first', 'second');
        $builder->alias('second', 'third');
        $builder->alias('third', 'first');

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage(
            'The definitions contain a circular alias chain.',
        );

        $builder->build();
    }

    public function testTagsPreserveOrderAndDistinctAliasMembership(): void
    {
        $builder = new DefinitionBuilder();

        $builder->tag('handlers', 'alias', 'service', 'alias');
        $builder->tag('handlers', 'other', 'service');
        $builder->alias('alias', 'service');
        $builder->add(ServiceDefinition::forValue('service', null));
        $builder->add(ServiceDefinition::forValue('other', null));

        self::assertSame(
            [
                [
                    'name' => 'handlers',
                    'identifiers' => ['alias', 'service', 'other'],
                ],
            ],
            $builder->build()->tags,
        );
    }

    public function testInvalidTagCallDoesNotPartiallyAddMembers(): void
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forValue('service', null));

        self::captureFailure(
            static fn () => $builder->tag('handlers', 'service', ''),
        );

        self::assertSame([], $builder->build()->tags);
    }

    public function testMissingTagMemberFailsUntilItsDefinitionExists(): void
    {
        $builder = new DefinitionBuilder();

        $builder->tag('handlers', 'future');

        self::captureFailure(static fn () => $builder->build());

        $builder->add(ServiceDefinition::forValue('future', null));

        self::assertSame(
            [['name' => 'handlers', 'identifiers' => ['future']]],
            $builder->build()->tags,
        );
    }

    public function testEmptyTagMemberListIsANoOp(): void
    {
        $builder = new DefinitionBuilder();

        $builder->tag('handlers');

        self::assertSame([], $builder->build()->tags);
    }

    public function testContextReferencesAreResolvedThroughAliases(): void
    {
        $builder = new DefinitionBuilder();

        $builder->bindContext(
            'consumer.alias',
            'Application\\Dependency',
            'target.alias',
        );

        $builder->alias('consumer.alias', 'consumer');
        $builder->alias('target.alias', 'target');

        $builder->add(ServiceDefinition::forAutowire(
            'consumer',
            'Application\\Consumer',
        ));

        $builder->add(ServiceDefinition::forValue('target', null));

        self::assertSame(
            [
                [
                    'consumer' => 'consumer',
                    'dependency' => 'Application\\Dependency',
                    'target' => 'target',
                ],
            ],
            $builder->build()->contexts,
        );
    }

    public function testCanonicalContextDuplicatesAreRejected(): void
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'consumer',
            'Application\\Consumer',
        ));
        $builder->add(ServiceDefinition::forValue('target', null));
        $builder->alias('consumer.alias', 'consumer');

        $builder->bindContext('consumer', 'Dependency', 'target');
        $builder->bindContext('consumer.alias', 'Dependency', 'target');

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage(
            'A contextual dependency is bound more than once.',
        );

        $builder->build();
    }

    public function testLiteralCannotBeAContextualConsumer(): void
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forValue('consumer', null));
        $builder->add(ServiceDefinition::forValue('target', null));
        $builder->bindContext('consumer', 'Dependency', 'target');

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage(
            'A contextual consumer must be an autowired definition.',
        );

        $builder->build();
    }

    public function testMissingContextReferencesCanBeAddedAfterFailure(): void
    {
        $builder = new DefinitionBuilder();

        $builder->bindContext('consumer', 'Dependency', 'target');

        self::captureFailure(static fn () => $builder->build());

        $builder->add(ServiceDefinition::forAutowire(
            'consumer',
            'Application\\Consumer',
        ));

        self::captureFailure(static fn () => $builder->build());

        $builder->add(ServiceDefinition::forValue('target', null));

        self::assertCount(1, $builder->build()->contexts);
    }

    public function testNumericIdentifiersRemainDistinct(): void
    {
        $builder = new DefinitionBuilder();
        $first = ServiceDefinition::forValue('1', 'first');
        $second = ServiceDefinition::forValue('01', 'second');

        $builder->add($first);
        $builder->add($second);
        $builder->alias('0', '01');

        $set = $builder->build();

        self::assertSame([$first, $second], $set->services);
        self::assertSame(
            [['alias' => '0', 'target' => '01']],
            $set->aliases,
        );
    }

    public function testEarlierSnapshotIsUnaffectedByLaterBuilderChanges(): void
    {
        $builder = new DefinitionBuilder();
        $first = ServiceDefinition::forValue('first', null);

        $builder->add($first);
        $builder->tag('handlers', 'first');

        $before = $builder->build();

        $builder->add(ServiceDefinition::forValue('second', null));
        $builder->tag('handlers', 'second');

        $after = $builder->build();

        self::assertSame([$first], $before->services);
        self::assertSame(
            [['name' => 'handlers', 'identifiers' => ['first']]],
            $before->tags,
        );

        self::assertCount(2, $after->services);
        self::assertSame(
            [['name' => 'handlers', 'identifiers' => ['first', 'second']]],
            $after->tags,
        );
    }

    public function testRepeatedBuildsHaveEquivalentContents(): void
    {
        $builder = new DefinitionBuilder();

        $builder->alias('alias', 'service');
        $builder->add(ServiceDefinition::forValue('service', 1));

        $first = $builder->build();
        $second = $builder->build();

        self::assertNotSame($first, $second);
        self::assertSame($first->services, $second->services);
        self::assertSame($first->aliases, $second->aliases);
        self::assertSame($first->tags, $second->tags);
        self::assertSame($first->contexts, $second->contexts);
    }

    public function testEmptyNamesAreRejectedWithoutChangingBuilder(): void
    {
        $builder = new DefinitionBuilder();

        self::captureFailure(
            static fn () => $builder->alias('', 'target'),
        );
        self::captureFailure(
            static fn () => $builder->alias('alias', ''),
        );
        self::captureFailure(
            static fn () => $builder->tag('', 'service'),
        );
        self::captureFailure(
            static fn () => $builder->bindContext('', 'Dependency', 'target'),
        );
        self::captureFailure(
            static fn () => $builder->bindContext('consumer', '', 'target'),
        );
        self::captureFailure(
            static fn () => $builder->bindContext('consumer', 'Dependency', ''),
        );

        $set = $builder->build();

        self::assertSame([], $set->aliases);
        self::assertSame([], $set->tags);
        self::assertSame([], $set->contexts);
    }

    /**
     * @param Closure(): mixed $operation
     */
    private static function captureFailure(
        Closure $operation,
    ): DefinitionException {
        try {
            $operation();
        } catch (DefinitionException $exception) {
            return $exception;
        }

        self::fail('The operation must reject the definitions.');
    }
}
