<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container\Compilation;

use Careminate\Container\Compilation\DefinitionArtifactCodec;
use Careminate\Container\Compilation\DefinitionBuilder;
use Careminate\Container\Compilation\DefinitionSet;
use Careminate\Container\Compilation\Exception\ArtifactException;
use Careminate\Container\Compilation\FilesystemArtifactStore;
use Careminate\Container\Compilation\ServiceDefinition;
use Closure;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

final class FilesystemArtifactStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'careminate-artifacts-'
            . bin2hex(random_bytes(12));

        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        foreach (new FilesystemIterator($this->directory) as $entry) {
            self::assertInstanceOf(SplFileInfo::class, $entry);

            $path = $entry->getPathname();

            if ($entry->isDir() && !$entry->isLink()) {
                self::assertTrue(rmdir($path));
            } else {
                self::assertTrue(unlink($path));
            }
        }

        self::assertTrue(rmdir($this->directory));
    }

    public function testPublishedArtifactCanBeLoadedByAnotherStore(): void
    {
        $definitions = $this->definitions('first');
        $writer = new FilesystemArtifactStore($this->directory);

        $fingerprint = $writer->save('main', $definitions, 'build-1');

        $reader = new FilesystemArtifactStore($this->directory);
        $restored = $reader->load('main', 'build-1', $fingerprint);

        self::assertSame('first', $restored->services[0]->value());
        self::assertSame(
            new DefinitionArtifactCodec()->fingerprint($definitions),
            $fingerprint,
        );
        self::assertSame(['container-main.json'], $this->filenames());
    }

    public function testExistingArtifactCanBeReplaced(): void
    {
        $store = new FilesystemArtifactStore($this->directory);

        $oldFingerprint = $store->save(
            'main',
            $this->definitions('old'),
            'build-1',
        );

        $newFingerprint = $store->save(
            'main',
            $this->definitions('new'),
            'build-2',
        );

        self::assertNotSame($oldFingerprint, $newFingerprint);

        $restored = $store->load('main', 'build-2', $newFingerprint);

        self::assertSame('new', $restored->services[0]->value());
        self::assertSame(['container-main.json'], $this->filenames());

        self::captureFailure(
            static fn (): DefinitionSet => $store->load(
                'main',
                'build-1',
                $oldFingerprint,
            ),
        );
    }

    public function testEncodingFailureLeavesExistingArtifactUntouched(): void
    {
        $store = new FilesystemArtifactStore($this->directory);
        $fingerprint = $store->save(
            'main',
            $this->definitions('original'),
            'build-1',
        );

        $invalid = new DefinitionSet(
            services: [],
            aliases: [['alias' => 'alias', 'target' => 'missing']],
            tags: [],
            contexts: [],
        );

        self::captureFailure(
            static fn (): string => $store->save(
                'main',
                $invalid,
                'build-2',
            ),
        );

        self::assertSame(
            'original',
            $store->load('main', 'build-1', $fingerprint)->services[0]->value(),
        );
        self::assertSame(['container-main.json'], $this->filenames());
    }

    public function testPublicationFailureCleansUpTemporaryFile(): void
    {
        $destination = $this->directory
            . DIRECTORY_SEPARATOR
            . 'container-main.json';

        self::assertTrue(mkdir($destination));

        $store = new FilesystemArtifactStore($this->directory);

        $failure = self::captureFailure(
            fn (): string => $store->save(
                'main',
                $this->definitions('value'),
                'build-1',
            ),
        );

        self::assertSame(
            'The artifact could not be published.',
            $failure->getMessage(),
        );
        self::assertDirectoryExists($destination);
        self::assertSame(['container-main.json'], $this->filenames());
    }

    public function testMissingArtifactFailsWithoutCreatingFiles(): void
    {
        $store = new FilesystemArtifactStore($this->directory);

        self::captureFailure(
            static fn (): DefinitionSet => $store->load(
                'missing',
                'build-1',
                str_repeat('0', 64),
            ),
        );

        self::assertSame([], $this->filenames());
    }

    public function testMalformedArtifactIsRejected(): void
    {
        $path = $this->directory
            . DIRECTORY_SEPARATOR
            . 'container-main.json';

        self::assertSame(1, file_put_contents($path, '['));

        $store = new FilesystemArtifactStore($this->directory);

        $this->expectException(ArtifactException::class);

        $store->load('main', 'build-1', str_repeat('0', 64));
    }

    public function testOversizedArtifactIsRejectedBeforeDecoding(): void
    {
        $path = $this->directory
            . DIRECTORY_SEPARATOR
            . 'container-main.json';

        self::assertSame(
            1048577,
            file_put_contents($path, str_repeat(' ', 1048577)),
        );

        $store = new FilesystemArtifactStore($this->directory);

        $this->expectException(ArtifactException::class);
        $this->expectExceptionMessage(
            'The artifact exceeds its file size limit.',
        );

        $store->load('main', 'build-1', str_repeat('0', 64));
    }

    public function testUnexpectedFingerprintIsRejected(): void
    {
        $store = new FilesystemArtifactStore($this->directory);

        $store->save('main', $this->definitions('value'), 'build-1');

        $this->expectException(ArtifactException::class);

        $store->load('main', 'build-1', str_repeat('0', 64));
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidKeysAreRejectedForReadingAndWriting(
        string $key,
    ): void {
        $store = new FilesystemArtifactStore($this->directory);
        $definitions = $this->definitions('value');

        self::captureFailure(
            static fn (): string => $store->save(
                $key,
                $definitions,
                'build-1',
            ),
        );

        self::captureFailure(
            static fn (): DefinitionSet => $store->load(
                $key,
                'build-1',
                str_repeat('0', 64),
            ),
        );

        self::assertSame([], $this->filenames());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'parent traversal' => ['../outside'];
        yield 'Windows separator' => ['..\\outside'];
        yield 'absolute path' => ['/outside'];
        yield 'drive path' => ['C:\\outside'];
        yield 'stream wrapper' => ['php://memory'];
        yield 'null byte' => ["entry\0"];
        yield 'uppercase' => ['Main'];
        yield 'leading hyphen' => ['-main'];
        yield 'too long' => [str_repeat('a', 65)];
    }

    public function testMaximumKeyLengthIsAccepted(): void
    {
        $store = new FilesystemArtifactStore($this->directory);
        $key = str_repeat('a', 64);

        $fingerprint = $store->save(
            $key,
            $this->definitions(null),
            'build-1',
        );

        self::assertNull(
            $store->load($key, 'build-1', $fingerprint)->services[0]->value(),
        );
    }

    public function testDirectoryMustAlreadyExist(): void
    {
        $missing = $this->directory . DIRECTORY_SEPARATOR . 'missing';

        $this->expectException(ArtifactException::class);

        new FilesystemArtifactStore($missing);
    }

    public function testRegularFileCannotBeTheStoreDirectory(): void
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . 'file';

        self::assertSame(0, file_put_contents($path, ''));

        $this->expectException(ArtifactException::class);

        new FilesystemArtifactStore($path);
    }

    private function definitions(mixed $value): DefinitionSet
    {
        $builder = new DefinitionBuilder();
        $builder->add(ServiceDefinition::forValue('value', $value));

        return $builder->build();
    }

    /**
     * @return list<string>
     */
    private function filenames(): array
    {
        $names = [];

        foreach (new FilesystemIterator($this->directory) as $entry) {
            self::assertInstanceOf(SplFileInfo::class, $entry);

            $names[] = $entry->getFilename();
        }

        sort($names, SORT_STRING);

        return $names;
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

        self::fail('The filesystem artifact operation must fail.');
    }
}
