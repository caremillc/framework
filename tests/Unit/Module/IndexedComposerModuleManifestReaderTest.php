<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use ArrayObject;
use Careminate\Module\Exception\ModuleDiscoveryException;
use Careminate\Module\Internal\ComposerModuleManifestReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IndexedComposerModuleManifestReaderTest extends TestCase
{
    public function testIndexedDeclarationsPreserveIdentityAndPackage(): void
    {
        $entries = new ComposerModuleManifestReader()->readIndexed([
            'example/zeta' => self::manifest('example/zeta', [
                ['id' => 'zeta', 'class' => 'Example\\ZetaModule'],
            ]),
            'example/alpha' => self::manifest('example/alpha', [
                ['id' => 'alpha', 'class' => 'Example\\AlphaModule'],
            ]),
        ]);

        self::assertCount(2, $entries);

        self::assertSame('example/alpha', $entries[0]->package);
        self::assertSame('Example\\AlphaModule', $entries[0]->className);
        self::assertSame('alpha', $entries[0]->moduleId?->value);

        self::assertSame('example/zeta', $entries[1]->package);
        self::assertSame('Example\\ZetaModule', $entries[1]->className);
        self::assertSame('zeta', $entries[1]->moduleId?->value);
    }

    public function testReadingDoesNotAutoloadDeclaredClasses(): void
    {
        $className = 'CareminateManifestProbe\\UnloadedModule';

        /** @var ArrayObject<int, string> $attempts */
        $attempts = new ArrayObject();

        $loader = static function (string $requested) use (
            $className,
            $attempts,
        ): void {
            if (strcasecmp($requested, $className) === 0) {
                $attempts->append($requested);
            }
        };

        self::assertFalse(class_exists($className, false));

        spl_autoload_register($loader, true, true);

        try {
            $entries = new ComposerModuleManifestReader()->readIndexed([
                'example/probe' => self::manifest('example/probe', [
                    ['id' => 'probe', 'class' => $className],
                ]),
            ]);
        } finally {
            spl_autoload_unregister($loader);
        }

        self::assertCount(1, $entries);
        self::assertSame([], $attempts->getArrayCopy());
        self::assertFalse(class_exists($className, false));
    }

    public function testDuplicateIdentifiersAcrossPackagesAreRejected(): void
    {
        $this->expectException(ModuleDiscoveryException::class);
        $this->expectExceptionMessage(
            'An indexed module identifier is declared more than once.',
        );

        new ComposerModuleManifestReader()->readIndexed([
            'example/first' => self::manifest('example/first', [
                ['id' => 'shared', 'class' => 'Example\\FirstModule'],
            ]),
            'example/second' => self::manifest('example/second', [
                ['id' => 'shared', 'class' => 'Example\\SecondModule'],
            ]),
        ]);
    }

    public function testDuplicateClassesAreComparedCaseInsensitively(): void
    {
        $this->expectException(ModuleDiscoveryException::class);
        $this->expectExceptionMessage(
            'A module entry-point class is declared more than once.',
        );

        new ComposerModuleManifestReader()->readIndexed([
            'example/modules' => self::manifest('example/modules', [
                ['id' => 'first', 'class' => 'Example\\SharedModule'],
                ['id' => 'second', 'class' => 'example\\sharedmodule'],
            ]),
        ]);
    }

    #[DataProvider('invalidDeclarations')]
    public function testInvalidIndexedDeclarationsAreRejected(
        mixed $declaration,
    ): void {
        $this->expectException(ModuleDiscoveryException::class);

        new ComposerModuleManifestReader()->readIndexed([
            'example/module' => self::manifest(
                'example/module',
                [$declaration],
            ),
        ]);
    }

    public function testLegacyReaderStillAcceptsClassNames(): void
    {
        $entries = new ComposerModuleManifestReader()->read([
            'example/module' => self::manifest('example/module', [
                'Example\\LegacyModule',
            ]),
        ]);

        self::assertCount(1, $entries);
        self::assertSame('Example\\LegacyModule', $entries[0]->className);
        self::assertNull($entries[0]->moduleId);
    }

    public function testLegacyReaderRejectsIndexedDeclarations(): void
    {
        $this->expectException(ModuleDiscoveryException::class);

        new ComposerModuleManifestReader()->read([
            'example/module' => self::manifest('example/module', [
                ['id' => 'module', 'class' => 'Example\\Module'],
            ]),
        ]);
    }

    public function testIndexedReaderAcceptsEmptyModuleList(): void
    {
        self::assertSame(
            [],
            new ComposerModuleManifestReader()->readIndexed([
                'example/empty' => self::manifest('example/empty', []),
            ]),
        );
    }

    public function testIndexedReaderRejectsPackageNameMismatch(): void
    {
        $this->expectException(ModuleDiscoveryException::class);

        new ComposerModuleManifestReader()->readIndexed([
            'example/expected' => self::manifest('example/actual', []),
        ]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidDeclarations(): iterable
    {
        yield 'legacy class string' => ['Example\\Module'];
        yield 'null' => [null];
        yield 'list' => [['module', 'Example\\Module']];
        yield 'missing class' => [['id' => 'module']];
        yield 'missing identifier' => [['class' => 'Example\\Module']];
        yield 'unknown field' => [[
            'id' => 'module',
            'class' => 'Example\\Module',
            'enabled' => true,
        ]];
        yield 'invalid identifier' => [[
            'id' => 'Invalid Identifier',
            'class' => 'Example\\Module',
        ]];
        yield 'non-string identifier' => [[
            'id' => 1,
            'class' => 'Example\\Module',
        ]];
        yield 'invalid class syntax' => [[
            'id' => 'module',
            'class' => 'Example/Module',
        ]];
        yield 'non-string class' => [[
            'id' => 'module',
            'class' => false,
        ]];
    }

    /**
     * @param list<mixed> $modules
     */
    private static function manifest(
        string $package,
        array $modules,
    ): string {
        return json_encode(
            [
                'name' => $package,
                'extra' => [
                    'careminate' => [
                        'modules' => $modules,
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR,
        );
    }
}
