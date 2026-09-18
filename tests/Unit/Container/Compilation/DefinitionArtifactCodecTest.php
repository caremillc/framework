<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container\Compilation;

use Careminate\Container\Compilation\DefinitionArtifactCodec;
use Careminate\Container\Compilation\DefinitionBuilder;
use Careminate\Container\Compilation\DefinitionContainerFactory;
use Careminate\Container\Compilation\DefinitionLifetime;
use Careminate\Container\Compilation\DefinitionSet;
use Careminate\Container\Compilation\Exception\ArtifactException;
use Careminate\Container\Compilation\Exception\DefinitionException;
use Careminate\Container\Compilation\Internal\ArtifactEnvelopeCodec;
use Careminate\Container\Compilation\Internal\DefinitionPayloadCodec;
use Careminate\Container\Compilation\Internal\PortableValueCodec;
use Careminate\Container\Compilation\ServiceDefinition;
use CareminateIntegration\Tests\Fixtures\Container\DefinitionConsumer;
use CareminateIntegration\Tests\Fixtures\Container\DefinitionDependency;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DefinitionArtifactCodecTest extends TestCase
{
    public function testCompleteDefinitionSetRoundTripsDeterministically(): void
    {
        $definitions = $this->definitions();
        $codec = new DefinitionArtifactCodec();

        $artifact = $codec->encode($definitions, 'build-1');
        $fingerprint = $codec->fingerprint($definitions);
        $decoded = $codec->decode($artifact, 'build-1', $fingerprint);

        self::assertSame(
            $artifact,
            $codec->encode($decoded, 'build-1'),
        );
        self::assertSame($fingerprint, $codec->fingerprint($decoded));
        self::assertSame($definitions->aliases, $decoded->aliases);
        self::assertSame($definitions->tags, $decoded->tags);
        self::assertSame($definitions->contexts, $decoded->contexts);

        self::assertSame(
            array_map(
                static fn (ServiceDefinition $definition): string => $definition->id,
                $definitions->services,
            ),
            array_map(
                static fn (ServiceDefinition $definition): string => $definition->id,
                $decoded->services,
            ),
        );
    }

    public function testDecodedDefinitionsConfigureAWorkingContainer(): void
    {
        $definitions = $this->definitions();
        $codec = new DefinitionArtifactCodec();

        $decoded = $codec->decode(
            $codec->encode($definitions, 'build-1'),
            'build-1',
            $codec->fingerprint($definitions),
        );

        $container = new DefinitionContainerFactory()->create($decoded);
        $consumer = $container->get('consumer.alias');

        self::assertInstanceOf(DefinitionConsumer::class, $consumer);
        self::assertSame('configured', $consumer->label);
        self::assertSame('dependency', $consumer->dependency->label);
        self::assertSame(["\0\xff", null], $consumer->handlers);
        self::assertSame($consumer, $container->get('consumer'));
        self::assertTrue($container->isFrozen());

        $lazy = $container->get('lazy');

        self::assertInstanceOf(DefinitionDependency::class, $lazy);

        $reflection = new ReflectionClass($lazy);

        self::assertTrue($reflection->isUninitializedLazyObject($lazy));
        self::assertSame('lazy', $lazy->label);
        self::assertFalse($reflection->isUninitializedLazyObject($lazy));

        $firstScoped = $container->runInScope(
            static fn (): mixed => $container->get('scoped'),
        );
        $secondScoped = $container->runInScope(
            static fn (): mixed => $container->get('scoped'),
        );

        self::assertInstanceOf(DefinitionDependency::class, $firstScoped);
        self::assertInstanceOf(DefinitionDependency::class, $secondScoped);
        self::assertNotSame($firstScoped, $secondScoped);
    }

    public function testEmptyDefinitionsRoundTrip(): void
    {
        $definitions = new DefinitionBuilder()->build();
        $codec = new DefinitionArtifactCodec();

        $decoded = $codec->decode(
            $codec->encode($definitions, 'empty-build'),
            'empty-build',
            $codec->fingerprint($definitions),
        );

        self::assertSame([], $decoded->services);
        self::assertSame([], $decoded->aliases);
        self::assertSame([], $decoded->tags);
        self::assertSame([], $decoded->contexts);
    }

    public function testLiteralTypesAndKeyOrderSurviveArtifactRoundTrip(): void
    {
        $value = [
            '01' => 1.0,
            1 => '1',
            '' => null,
            'binary' => "\0\xff",
            'negative-zero' => -0.0,
        ];

        $builder = new DefinitionBuilder();
        $builder->add(ServiceDefinition::forValue('literal', $value));

        $definitions = $builder->build();
        $codec = new DefinitionArtifactCodec();

        $decoded = $codec->decode(
            $codec->encode($definitions, 'build-1'),
            'build-1',
            $codec->fingerprint($definitions),
        );

        $container = new DefinitionContainerFactory()->create($decoded);
        $actual = $container->get('literal');

        self::assertSame($value, $actual);

        self::assertSame(
            pack('E', -0.0),
            pack('E', $actual['negative-zero']),
        );
    }

    public function testClassNamesRemainUnresolvedDuringArtifactRoundTrip(): void
    {
        $builder = new DefinitionBuilder();
        $builder->add(ServiceDefinition::forAutowire(
            'future',
            'CareminateArtifactFixture\\UnavailableService',
        ));

        $definitions = $builder->build();
        $codec = new DefinitionArtifactCodec();

        $decoded = $codec->decode(
            $codec->encode($definitions, 'build-1'),
            'build-1',
            $codec->fingerprint($definitions),
        );

        self::assertSame(
            'CareminateArtifactFixture\\UnavailableService',
            $decoded->services[0]->className,
        );
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedPayloadsAreRejected(mixed $document): void
    {
        $payload = new PortableValueCodec()->encode($document);

        $this->expectException(ArtifactException::class);

        new DefinitionPayloadCodec()->decode($payload);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'wrong version' => [[2, [], [], [], []]];
        yield 'missing collection' => [[1, [], [], []]];
        yield 'extra collection' => [[1, [], [], [], [], []]];
        yield 'non-list services' => [[1, ['named' => []], [], [], []]];
        yield 'unknown kind' => [[1, [['unknown', 'id']], [], [], []]];
        yield 'extra literal field' => [
            [1, [['value', 'id', null, 'extra']], [], [], []],
        ];
        yield 'non-string identifier' => [
            [1, [['value', 1, null]], [], [], []],
        ];
        yield 'unknown lifetime' => [
            [1, [['autowire', 'id', 'Example', 'unknown', false, [], []]], [], [], []],
        ];
        yield 'non-boolean lazy flag' => [
            [1, [['autowire', 'id', 'Example', 'singleton', 1, [], []]], [], [], []],
        ];
        yield 'non-array arguments' => [
            [1, [['autowire', 'id', 'Example', 'transient', false, null, []]], [], [], []],
        ];
        yield 'lazy transient' => [
            [1, [['autowire', 'id', 'Example', 'transient', true, [], []]], [], [], []],
        ];
        yield 'duplicate service' => [
            [1, [['value', 'id', null], ['value', 'id', 1]], [], [], []],
        ];
        yield 'missing alias target' => [
            [1, [], [['alias', 'missing']], [], []],
        ];
        yield 'alias cycle' => [
            [1, [], [['first', 'second'], ['second', 'first']], [], []],
        ];
        yield 'invalid tag member type' => [
            [1, [], [], [['tag', [false]]], []],
        ];
        yield 'missing tag member' => [
            [1, [], [], [['tag', ['missing']]], []],
        ];
        yield 'malformed context' => [
            [1, [], [], [], [['consumer', 'dependency']]],
        ];
    }

    public function testValidEnvelopeDoesNotMakeInvalidPayloadAcceptable(): void
    {
        $payload = new PortableValueCodec()->encode([1, [], [], []]);
        $envelope = new ArtifactEnvelopeCodec();

        $artifact = $envelope->encode($payload, 'build-1');

        $this->expectException(ArtifactException::class);

        new DefinitionArtifactCodec()->decode(
            $artifact,
            'build-1',
            $envelope->fingerprint($payload),
        );
    }

    public function testDirectInvalidSnapshotIsRejectedDuringEncoding(): void
    {
        $definitions = new DefinitionSet(
            services: [],
            aliases: [['alias' => 'alias', 'target' => 'missing']],
            tags: [],
            contexts: [],
        );

        $failure = self::captureFailure(
            static fn (): string => new DefinitionArtifactCodec()->encode(
                $definitions,
                'build-1',
            ),
        );

        self::assertInstanceOf(
            DefinitionException::class,
            $failure->getPrevious(),
        );
    }

    public function testChangedDefinitionsHaveADifferentFingerprint(): void
    {
        $first = new DefinitionBuilder();
        $first->add(ServiceDefinition::forValue('value', 1));

        $second = new DefinitionBuilder();
        $second->add(ServiceDefinition::forValue('value', '1'));

        $codec = new DefinitionArtifactCodec();

        self::assertNotSame(
            $codec->fingerprint($first->build()),
            $codec->fingerprint($second->build()),
        );
    }

    private function definitions(): DefinitionSet
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forValue('binary', "\0\xff"));
        $builder->add(ServiceDefinition::forValue('optional', null));

        $builder->add(ServiceDefinition::forAutowire(
            'dependency',
            DefinitionDependency::class,
            lifetime: DefinitionLifetime::Singleton,
            arguments: ['label' => 'dependency'],
        ));

        $builder->add(ServiceDefinition::forAutowire(
            'consumer',
            DefinitionConsumer::class,
            lifetime: DefinitionLifetime::Singleton,
            arguments: ['label' => 'configured'],
            taggedArguments: ['handlers' => 'handlers'],
        ));

        $builder->add(ServiceDefinition::forAutowire(
            'lazy',
            DefinitionDependency::class,
            lifetime: DefinitionLifetime::Singleton,
            arguments: ['label' => 'lazy'],
            lazy: true,
        ));

        $builder->add(ServiceDefinition::forAutowire(
            'scoped',
            DefinitionDependency::class,
            lifetime: DefinitionLifetime::Scoped,
        ));

        $builder->alias('consumer.alias', 'consumer');
        $builder->tag('handlers', 'binary', 'optional');
        $builder->bindContext(
            'consumer',
            DefinitionDependency::class,
            'dependency',
        );

        return $builder->build();
    }

    /**
     * @param Closure(): mixed $operation
     */
    private static function captureFailure(
        Closure $operation,
    ): ArtifactException {
        try {
            $operation();
        } catch (ArtifactException $exception) {
            return $exception;
        }

        self::fail('The artifact operation must fail.');
    }
}
