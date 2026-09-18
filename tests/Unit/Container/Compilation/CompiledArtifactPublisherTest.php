<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container\Compilation;

use Careminate\Container\Compilation\CompiledArtifactContainerLoader;
use Careminate\Container\Compilation\CompiledArtifactPublisher;
use Careminate\Container\Compilation\DefinitionArtifactCodec;
use Careminate\Container\Compilation\DefinitionBuilder;
use Careminate\Container\Compilation\DefinitionLifetime;
use Careminate\Container\Compilation\DefinitionSet;
use Careminate\Container\Compilation\Exception\ArtifactException;
use Careminate\Container\Compilation\Exception\DefinitionException;
use Careminate\Container\Compilation\FilesystemArtifactStore;
use Careminate\Container\Compilation\ServiceDefinition;
use Careminate\Container\Exception\ResolutionException;
use Careminate\Container\Internal\LazyClass;
use CareminateIntegration\Tests\Fixtures\Container\DefinitionDependency;
use FilesystemIterator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use stdClass;
use TypeError;

final class CompiledArtifactPublisherTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'careminate-compiled-publisher-'
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

    public function testPublishedDefinitionsLoadAsALazySingleton(): void
    {
        $store = new FilesystemArtifactStore($this->directory);
        $publisher = new CompiledArtifactPublisher($store);
        $definitions = $this->definitions('published');

        $fingerprint = $publisher->publish(
            'main',
            $definitions,
            'build-1',
        );

        self::assertSame(
            new DefinitionArtifactCodec()->fingerprint($definitions),
            $fingerprint,
        );

        $container = new CompiledArtifactContainerLoader($store)->load(
            'main',
            'build-1',
            $fingerprint,
        );

        self::assertTrue($container->isFrozen());

        $service = $container->get('service');

        self::assertInstanceOf(DefinitionDependency::class, $service);

        $lazyClass = new LazyClass(DefinitionDependency::class);

        self::assertTrue($lazyClass->isUninitialized($service));
        self::assertSame('published', $service->label);
        self::assertFalse($lazyClass->isUninitialized($service));
        self::assertSame($service, $container->get('service'));
    }

    public function testInvalidConstructorLeavesPublishedArtifactUntouched(): void
    {
        $store = new FilesystemArtifactStore($this->directory);
        $publisher = new CompiledArtifactPublisher($store);

        $fingerprint = $publisher->publish(
            'main',
            $this->definitions('original'),
            'build-1',
        );

        $before = file_get_contents($this->artifactPath());

        self::assertIsString($before);

        $builder = new DefinitionBuilder();
        $builder->add(ServiceDefinition::forAutowire(
            'service',
            DefinitionDependency::class,
            arguments: ['unknownParameter' => null],
        ));

        $failure = null;

        try {
            $publisher->publish('main', $builder->build(), 'build-2');
        } catch (DefinitionException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(DefinitionException::class, $failure);
        self::assertInstanceOf(
            InvalidArgumentException::class,
            $failure->getPrevious(),
        );

        self::assertSame($before, file_get_contents($this->artifactPath()));

        $this->assertOriginalCanBeLoaded($store, $fingerprint);
    }

    public function testIneligibleLazyClassLeavesPublishedArtifactUntouched(): void
    {
        $store = new FilesystemArtifactStore($this->directory);
        $publisher = new CompiledArtifactPublisher($store);

        $fingerprint = $publisher->publish(
            'main',
            $this->definitions('original'),
            'build-1',
        );

        $before = file_get_contents($this->artifactPath());

        self::assertIsString($before);

        $builder = new DefinitionBuilder();
        $builder->add(ServiceDefinition::forAutowire(
            'service',
            stdClass::class,
            lifetime: DefinitionLifetime::Singleton,
            lazy: true,
        ));

        $failure = null;

        try {
            $publisher->publish('main', $builder->build(), 'build-2');
        } catch (ResolutionException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(ResolutionException::class, $failure);
        self::assertInstanceOf(
            InvalidArgumentException::class,
            $failure->getPrevious(),
        );

        self::assertSame($before, file_get_contents($this->artifactPath()));

        $this->assertOriginalCanBeLoaded($store, $fingerprint);
    }

    public function testInvalidBuildIdentifierLeavesPublishedArtifactUntouched(): void
    {
        $store = new FilesystemArtifactStore($this->directory);
        $publisher = new CompiledArtifactPublisher($store);

        $fingerprint = $publisher->publish(
            'main',
            $this->definitions('original'),
            'build-1',
        );

        $before = file_get_contents($this->artifactPath());

        self::assertIsString($before);

        $failure = null;

        try {
            $publisher->publish(
                'main',
                $this->definitions('replacement'),
                '',
            );
        } catch (ArtifactException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(ArtifactException::class, $failure);
        self::assertSame($before, file_get_contents($this->artifactPath()));

        $this->assertOriginalCanBeLoaded($store, $fingerprint);
    }

    public function testSuccessfulReplacementLoadsTheNewDefinitions(): void
    {
        $store = new FilesystemArtifactStore($this->directory);
        $publisher = new CompiledArtifactPublisher($store);

        $oldFingerprint = $publisher->publish(
            'main',
            $this->definitions('original'),
            'build-1',
        );

        $newFingerprint = $publisher->publish(
            'main',
            $this->definitions('replacement'),
            'build-2',
        );

        self::assertNotSame($oldFingerprint, $newFingerprint);

        $loader = new CompiledArtifactContainerLoader($store);
        $container = $loader->load('main', 'build-2', $newFingerprint);
        $service = $container->get('service');

        self::assertInstanceOf(DefinitionDependency::class, $service);
        self::assertSame('replacement', $service->label);

        $this->expectException(ArtifactException::class);

        $loader->load('main', 'build-1', $oldFingerprint);
    }

    public function testPublicationDoesNotExecuteServiceConstructors(): void
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'service',
            DefinitionDependency::class,
            arguments: ['label' => 123],
        ));

        $store = new FilesystemArtifactStore($this->directory);
        $publisher = new CompiledArtifactPublisher($store);

        $fingerprint = $publisher->publish(
            'main',
            $builder->build(),
            'build-1',
        );

        $container = new CompiledArtifactContainerLoader($store)->load(
            'main',
            'build-1',
            $fingerprint,
        );

        self::assertTrue($container->has('service'));

        $failure = null;

        try {
            $container->get('service');
        } catch (ResolutionException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(ResolutionException::class, $failure);
        self::assertInstanceOf(TypeError::class, $failure->getPrevious());
        self::assertSame(['service'], $failure->dependencyPath());
    }

    private function definitions(string $label): DefinitionSet
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'service',
            DefinitionDependency::class,
            lifetime: DefinitionLifetime::Singleton,
            arguments: ['label' => $label],
            lazy: true,
        ));

        return $builder->build();
    }

    private function assertOriginalCanBeLoaded(
        FilesystemArtifactStore $store,
        string $fingerprint,
    ): void {
        $container = new CompiledArtifactContainerLoader($store)->load(
            'main',
            'build-1',
            $fingerprint,
        );

        $service = $container->get('service');

        self::assertInstanceOf(DefinitionDependency::class, $service);
        self::assertSame('original', $service->label);
    }

    private function artifactPath(): string
    {
        return $this->directory
            . DIRECTORY_SEPARATOR
            . 'container-main.json';
    }
}
