<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Module\CapabilityIdentifier;
use Careminate\Module\CapabilityProvision;
use Careminate\Module\Exception\InvalidModuleDefinitionException;
use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleIdentifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CapabilityMetadataTest extends TestCase
{
    #[DataProvider('validIdentifiers')]
    public function testValidCapabilityNamesArePreserved(string $value): void
    {
        self::assertSame(
            $value,
            new CapabilityIdentifier($value)->value,
        );
    }

    #[DataProvider('invalidIdentifiers')]
    public function testInvalidCapabilityNamesAreRejected(string $value): void
    {
        $this->expectException(InvalidModuleDefinitionException::class);

        new CapabilityIdentifier($value);
    }

    public function testCapabilityEqualityUsesItsValue(): void
    {
        $first = new CapabilityIdentifier('storage.primary');
        $same = new CapabilityIdentifier('storage.primary');
        $different = new CapabilityIdentifier('storage.archive');

        self::assertNotSame($first, $same);
        self::assertTrue($first->equals($same));
        self::assertTrue($same->equals($first));
        self::assertFalse($first->equals($different));
    }

    public function testExistingDefinitionsDefaultToNoCapabilities(): void
    {
        $definition = new ModuleDefinition(
            new ModuleIdentifier('billing'),
        );

        self::assertSame([], $definition->requiredCapabilities);
        self::assertSame([], $definition->providedCapabilities);
    }

    public function testProvisionDefaultsToShared(): void
    {
        $capability = new CapabilityIdentifier('search');
        $provision = new CapabilityProvision($capability);

        self::assertSame($capability, $provision->capability);
        self::assertFalse($provision->exclusive);
    }

    public function testDeclarationOrderAndExclusiveFlagsArePreserved(): void
    {
        $storage = new CapabilityIdentifier('storage');
        $search = new CapabilityIdentifier('search');

        $shared = new CapabilityProvision($search);
        $exclusive = new CapabilityProvision($storage, exclusive: true);

        $definition = new ModuleDefinition(
            new ModuleIdentifier('catalog'),
            requiredCapabilities: [$storage, $search],
            providedCapabilities: [$shared, $exclusive],
        );

        self::assertSame(
            [$storage, $search],
            $definition->requiredCapabilities,
        );
        self::assertSame(
            [$shared, $exclusive],
            $definition->providedCapabilities,
        );
        self::assertTrue(
            $definition->providedCapabilities[1]->exclusive,
        );
    }

    public function testDuplicateRequirementsAreRejectedByValue(): void
    {
        $this->expectException(InvalidModuleDefinitionException::class);
        $this->expectExceptionMessage(
            'A required capability cannot be declared more than once.',
        );

        new ModuleDefinition(
            new ModuleIdentifier('billing'),
            requiredCapabilities: [
                new CapabilityIdentifier('storage'),
                new CapabilityIdentifier('storage'),
            ],
        );
    }

    #[DataProvider('provisionModes')]
    public function testDuplicateProvisionsAreRejectedRegardlessOfExclusivity(
        bool $secondExclusive,
    ): void {
        $this->expectException(InvalidModuleDefinitionException::class);
        $this->expectExceptionMessage(
            'A provided capability cannot be declared more than once.',
        );

        new ModuleDefinition(
            new ModuleIdentifier('storage'),
            providedCapabilities: [
                new CapabilityProvision(new CapabilityIdentifier('files')),
                new CapabilityProvision(
                    new CapabilityIdentifier('files'),
                    exclusive: $secondExclusive,
                ),
            ],
        );
    }

    public function testModuleCanRequireACapabilityItProvides(): void
    {
        $definition = new ModuleDefinition(
            new ModuleIdentifier('storage'),
            requiredCapabilities: [
                new CapabilityIdentifier('files'),
            ],
            providedCapabilities: [
                new CapabilityProvision(
                    new CapabilityIdentifier('files'),
                    exclusive: true,
                ),
            ],
        );

        self::assertSame(
            'files',
            $definition->requiredCapabilities[0]->value,
        );
        self::assertSame(
            'files',
            $definition->providedCapabilities[0]->capability->value,
        );
    }

    public function testChangingInputArraysDoesNotChangeTheDefinition(): void
    {
        $storage = new CapabilityIdentifier('storage');
        $provision = new CapabilityProvision($storage);

        $required = [$storage];
        $provided = [$provision];

        $original = new ModuleDefinition(
            new ModuleIdentifier('catalog'),
            requiredCapabilities: $required,
            providedCapabilities: $provided,
        );

        $required = [];
        $provided = [];

        $updated = new ModuleDefinition(
            new ModuleIdentifier('catalog'),
            requiredCapabilities: $required,
            providedCapabilities: $provided,
        );

        self::assertSame([$storage], $original->requiredCapabilities);
        self::assertSame([$provision], $original->providedCapabilities);
        self::assertSame([], $updated->requiredCapabilities);
        self::assertSame([], $updated->providedCapabilities);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validIdentifiers(): iterable
    {
        yield 'single letter' => ['a'];
        yield 'simple name' => ['storage'];
        yield 'qualified name' => ['storage.primary'];
        yield 'hyphenated segment' => ['search.full-text'];
        yield 'numeric segment' => ['protocol.2'];
        yield 'maximum length' => [str_repeat('a', 64)];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIdentifiers(): iterable
    {
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'uppercase' => ['Storage'];
        yield 'leading digit' => ['2storage'];
        yield 'leading separator' => ['.storage'];
        yield 'trailing separator' => ['storage-'];
        yield 'adjacent separators' => ['storage.-primary'];
        yield 'repeated separator' => ['storage..primary'];
        yield 'space' => ['file storage'];
        yield 'underscore' => ['file_storage'];
        yield 'path separator' => ['storage/files'];
        yield 'newline' => ["storage\n"];
        yield 'null byte' => ["storage\0"];
        yield 'non ASCII' => ['almacén'];
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function provisionModes(): iterable
    {
        yield 'both shared' => [false];
        yield 'shared and exclusive' => [true];
    }
}
