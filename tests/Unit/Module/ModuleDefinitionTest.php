<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Module\Exception\InvalidModuleDefinitionException;
use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleIdentifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleDefinitionTest extends TestCase
{
    public function testModuleCanHaveNoDependencies(): void
    {
        $id = new ModuleIdentifier('billing');
        $definition = new ModuleDefinition($id);

        self::assertSame($id, $definition->id);
        self::assertSame([], $definition->required);
        self::assertSame([], $definition->optional);
    }

    public function testDependencyDeclarationOrderIsPreserved(): void
    {
        $identity = new ModuleIdentifier('identity');
        $catalog = new ModuleIdentifier('catalog');
        $audit = new ModuleIdentifier('audit');
        $search = new ModuleIdentifier('search');

        $definition = new ModuleDefinition(
            new ModuleIdentifier('billing'),
            required: [$identity, $catalog],
            optional: [$audit, $search],
        );

        self::assertSame(
            [$identity, $catalog],
            $definition->required,
        );
        self::assertSame(
            [$audit, $search],
            $definition->optional,
        );
    }

    public function testChangingInputArraysDoesNotChangeTheDefinition(): void
    {
        $identity = new ModuleIdentifier('identity');
        $audit = new ModuleIdentifier('audit');

        $required = [$identity];
        $optional = [$audit];

        $original = new ModuleDefinition(
            new ModuleIdentifier('billing'),
            $required,
            $optional,
        );

        $catalog = new ModuleIdentifier('catalog');
        $required[] = $catalog;
        $optional = [];

        $updated = new ModuleDefinition(
            new ModuleIdentifier('billing'),
            $required,
            $optional,
        );

        self::assertSame([$identity], $original->required);
        self::assertSame([$audit], $original->optional);
        self::assertSame([$identity, $catalog], $updated->required);
        self::assertSame([], $updated->optional);
    }

    /**
     * @param list<string> $required
     * @param list<string> $optional
     */
    #[DataProvider('invalidDependencies')]
    public function testInvalidDependencyDeclarationsAreRejected(
        array $required,
        array $optional,
        string $message,
    ): void {
        $requiredIdentifiers = array_map(
            static fn (string $value): ModuleIdentifier =>
                new ModuleIdentifier($value),
            $required,
        );

        $optionalIdentifiers = array_map(
            static fn (string $value): ModuleIdentifier =>
                new ModuleIdentifier($value),
            $optional,
        );

        $this->expectException(InvalidModuleDefinitionException::class);
        $this->expectExceptionMessage($message);

        new ModuleDefinition(
            new ModuleIdentifier('billing'),
            $requiredIdentifiers,
            $optionalIdentifiers,
        );
    }

    public function testRepeatedIdentifierInstanceIsRejected(): void
    {
        $dependency = new ModuleIdentifier('identity');

        $this->expectException(InvalidModuleDefinitionException::class);
        $this->expectExceptionMessage(
            'A dependency cannot be declared more than once.',
        );

        new ModuleDefinition(
            new ModuleIdentifier('billing'),
            required: [$dependency, $dependency],
        );
    }

    /**
     * @return iterable<string, array{list<string>, list<string>, string}>
     */
    public static function invalidDependencies(): iterable
    {
        yield 'required self dependency' => [
            ['billing'],
            [],
            'A module cannot depend on itself.',
        ];

        yield 'optional self dependency' => [
            [],
            ['billing'],
            'A module cannot depend on itself.',
        ];

        yield 'duplicate required values' => [
            ['identity', 'identity'],
            [],
            'A dependency cannot be declared more than once.',
        ];

        yield 'duplicate optional values' => [
            [],
            ['audit', 'audit'],
            'A dependency cannot be declared more than once.',
        ];

        yield 'non adjacent duplicate' => [
            ['identity', 'catalog', 'identity'],
            [],
            'A dependency cannot be declared more than once.',
        ];

        yield 'required and optional overlap' => [
            ['identity'],
            ['identity'],
            'A dependency cannot be both required and optional.',
        ];

        yield 'overlap among multiple dependencies' => [
            ['identity', 'catalog'],
            ['audit', 'catalog'],
            'A dependency cannot be both required and optional.',
        ];
    }
}
