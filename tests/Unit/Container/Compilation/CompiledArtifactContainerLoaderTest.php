<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container\Compilation;

use Careminate\Container\Compilation\CompiledArtifactContainerLoader;
use Careminate\Container\Compilation\DefinitionBuilder;
use Careminate\Container\Compilation\DefinitionLifetime;
use Careminate\Container\Compilation\DefinitionSet;
use Careminate\Container\Compilation\Exception\ArtifactException;
use Careminate\Container\Compilation\Exception\DefinitionException;
use Careminate\Container\Compilation\FilesystemArtifactStore;
use Careminate\Container\Compilation\ServiceDefinition;
use Careminate\Container\Internal\LazyClass;
use CareminateIntegration\Tests\Fixtures\Container\DefinitionConsumer;
use CareminateIntegration\Tests\Fixtures\Container\DefinitionDependency;
use FilesystemIterator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

final class CompiledArtifactContainerLoaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'careminate-compiled-loader-'
            . bin2hex(random_bytes(12));

        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        foreach (new FilesystemIterator($this->directory) as $entry) {
            self::assertInstanceOf(SplFileInfo::class, $entry);
            self::assertTrue(unlink($entry->getPathname()));
        }

        self::assertTrue(rmdir($this->directory));
    }

    public function testLoadedLazyContainerPreservesDefinitionRelationships(): void
    {
        $writer = new FilesystemArtifactStore($this->directory);
        $fingerprint = $writer->save(
            'main',
            $this->lazyDefinitions(),
            'build-1',
        );

        $loader = new CompiledArtifactContainerLoader(
            new FilesystemArtifactStore($this->directory),
        );

        $container = $loader->load('main', 'build-1', $fingerprint);

        self::assertTrue($container->isFrozen());
        self::assertTrue($container->has('consumer.alias'));
        self::assertNull($container->get('first'));

        $consumer = $container->get('consumer.alias');

        self::assertInstanceOf(DefinitionConsumer::class, $consumer);
        self::assertSame($consumer, $container->get('consumer'));

        $lazyClass = new LazyClass(DefinitionConsumer::class);

        self::assertTrue($lazyClass->isUninitialized($consumer));

        self::assertSame('restored', $consumer->label);
        self::assertSame([null, 'handled'], $consumer->handlers);
        self::assertSame('contextual', $consumer->dependency->label);
        self::assertSame(
            $container->get('dependency.alias'),
            $consumer->dependency,
        );

        self::assertFalse($lazyClass->isUninitialized($consumer));
        self::assertSame($consumer, $container->get('consumer.alias'));
    }

    public function testRepeatedLoadsCreateIndependentSingletons(): void
    {
        $store = new FilesystemArtifactStore($this->directory);
        $fingerprint = $store->save(
            'main',
            $this->lazyDefinitions(),
            'build-1',
        );
        $loader = new CompiledArtifactContainerLoader($store);

        $firstContainer = $loader->load('main', 'build-1', $fingerprint);
        $secondContainer = $loader->load('main', 'build-1', $fingerprint);

        self::assertNotSame($firstContainer, $secondContainer);

        $first = $firstContainer->get('consumer');
        $second = $secondContainer->get('consumer');

        self::assertInstanceOf(DefinitionConsumer::class, $first);
        self::assertInstanceOf(DefinitionConsumer::class, $second);
        self::assertNotSame($first, $second);

        $lazyClass = new LazyClass(DefinitionConsumer::class);

        self::assertTrue($lazyClass->isUninitialized($first));
        self::assertTrue($lazyClass->isUninitialized($second));

        $firstDependency = $first->dependency;

        self::assertFalse($lazyClass->isUninitialized($first));
        self::assertTrue($lazyClass->isUninitialized($second));

        $secondDependency = $second->dependency;

        self::assertNotSame($firstDependency, $secondDependency);
        self::assertSame($first, $firstContainer->get('consumer.alias'));
        self::assertSame($second, $secondContainer->get('consumer.alias'));
    }

    #[DataProvider('identityMismatches')]
    public function testArtifactIdentityIsCheckedBeforeConstructorPreparation(
        bool $wrongBuild,
        bool $wrongFingerprint,
    ): void {
        $store = new FilesystemArtifactStore($this->directory);

        $fingerprint = $store->save(
            'main',
            $this->invalidConstructorDefinitions(),
            'build-1',
        );

        $expectedBuild = $wrongBuild ? 'build-2' : 'build-1';
        $expectedFingerprint = $wrongFingerprint
            ? ($fingerprint[0] === '0' ? '1' : '0') . substr($fingerprint, 1)
            : $fingerprint;

        $loader = new CompiledArtifactContainerLoader($store);

        $this->expectException(ArtifactException::class);

        $loader->load('main', $expectedBuild, $expectedFingerprint);
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function identityMismatches(): iterable
    {
        yield 'build mismatch' => [true, false];
        yield 'fingerprint mismatch' => [false, true];
        yield 'both mismatch' => [true, true];
    }

    public function testValidArtifactStillRequiresValidConstructorMetadata(): void
    {
        $store = new FilesystemArtifactStore($this->directory);
        $fingerprint = $store->save(
            'main',
            $this->invalidConstructorDefinitions(),
            'build-1',
        );

        $loader = new CompiledArtifactContainerLoader($store);

        try {
            $loader->load('main', 'build-1', $fingerprint);
        } catch (DefinitionException $exception) {
            self::assertSame(
                'A compiled constructor could not be prepared.',
                $exception->getMessage(),
            );
            self::assertInstanceOf(
                InvalidArgumentException::class,
                $exception->getPrevious(),
            );

            return;
        }

        self::fail('Invalid constructor metadata must remain a compilation failure.');
    }

    public function testMissingArtifactFailureIsPreserved(): void
    {
        $loader = new CompiledArtifactContainerLoader(
            new FilesystemArtifactStore($this->directory),
        );

        $this->expectException(ArtifactException::class);
        $this->expectExceptionMessage(
            'The artifact must be an existing regular file.',
        );

        $loader->load('missing', 'build-1', str_repeat('0', 64));
    }

    public function testMalformedArtifactIsRejected(): void
    {
        $path = $this->directory
            . DIRECTORY_SEPARATOR
            . 'container-main.json';

        self::assertSame(1, file_put_contents($path, '['));

        $loader = new CompiledArtifactContainerLoader(
            new FilesystemArtifactStore($this->directory),
        );

        $this->expectException(ArtifactException::class);

        $loader->load('main', 'build-1', str_repeat('0', 64));
    }

    public function testFailedLoadDoesNotPreventLaterValidLoad(): void
    {
        $store = new FilesystemArtifactStore($this->directory);
        $fingerprint = $store->save(
            'main',
            $this->lazyDefinitions(),
            'build-1',
        );

        $loader = new CompiledArtifactContainerLoader($store);
        $failure = null;

        try {
            $loader->load('main', 'wrong-build', $fingerprint);
        } catch (ArtifactException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(ArtifactException::class, $failure);

        $container = $loader->load('main', 'build-1', $fingerprint);
        $consumer = $container->get('consumer');

        self::assertInstanceOf(DefinitionConsumer::class, $consumer);
        self::assertSame('restored', $consumer->label);
        self::assertTrue($container->isFrozen());
    }

    private function lazyDefinitions(): DefinitionSet
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'consumer',
            DefinitionConsumer::class,
            lifetime: DefinitionLifetime::Singleton,
            arguments: ['label' => 'restored'],
            taggedArguments: ['handlers' => 'handlers'],
            lazy: true,
        ));
        $builder->add(ServiceDefinition::forAutowire(
            'dependency',
            DefinitionDependency::class,
            lifetime: DefinitionLifetime::Singleton,
            arguments: ['label' => 'contextual'],
        ));
        $builder->add(ServiceDefinition::forValue('first', null));
        $builder->add(ServiceDefinition::forValue('second', 'handled'));

        $builder->alias('consumer.alias', 'consumer');
        $builder->alias('dependency.alias', 'dependency');
        $builder->tag('handlers', 'first', 'second');
        $builder->bindContext(
            'consumer.alias',
            DefinitionDependency::class,
            'dependency.alias',
        );

        return $builder->build();
    }

    private function invalidConstructorDefinitions(): DefinitionSet
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'invalid',
            DefinitionDependency::class,
            arguments: ['unknownParameter' => null],
        ));

        return $builder->build();
    }
}
