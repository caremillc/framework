<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Module\Exception\InvalidModuleVersionException;
use Careminate\Module\ModuleVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleVersionTest extends TestCase
{
    #[DataProvider('validVersions')]
    public function testValidVersionsPreserveTheirExactRepresentation(
        string $value,
    ): void {
        $version = new ModuleVersion($value);

        self::assertSame($value, $version->value);
        self::assertSame(0, $version->compare(new ModuleVersion($value)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validVersions(): iterable
    {
        yield 'zero' => ['0.0.0'];
        yield 'stable' => ['12.34.56'];
        yield 'numeric prerelease' => ['1.0.0-0'];
        yield 'mixed prerelease' => ['1.0.0-alpha.12'];
        yield 'alphanumeric leading zero' => ['1.0.0-01alpha'];
        yield 'hyphen identifier' => ['1.0.0--'];
        yield 'build leading zeroes' => ['1.0.0+001'];
        yield 'prerelease and build' => ['1.0.0-rc.1+build.002'];
        yield 'case preserved' => ['1.0.0-RC.Build+Metadata'];
        yield 'large numeric component' => [
            '999999999999999999999999999999.0.0',
        ];
        yield 'maximum bytes' => [
            '1.0.0+' . str_repeat('a', 250),
        ];
    }

    #[DataProvider('invalidVersions')]
    public function testInvalidVersionsAreRejected(string $value): void
    {
        $this->expectException(InvalidModuleVersionException::class);

        new ModuleVersion($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidVersions(): iterable
    {
        yield 'empty' => [''];
        yield 'major only' => ['1'];
        yield 'missing patch' => ['1.2'];
        yield 'extra component' => ['1.2.3.4'];
        yield 'version prefix' => ['v1.2.3'];
        yield 'negative major' => ['-1.2.3'];
        yield 'major leading zero' => ['01.2.3'];
        yield 'minor leading zero' => ['1.02.3'];
        yield 'patch leading zero' => ['1.2.03'];
        yield 'prerelease leading zero' => ['1.2.3-01'];
        yield 'nested prerelease leading zero' => ['1.2.3-alpha.01'];
        yield 'empty prerelease' => ['1.2.3-'];
        yield 'empty prerelease identifier' => ['1.2.3-alpha..1'];
        yield 'empty build' => ['1.2.3+'];
        yield 'empty build identifier' => ['1.2.3+build..1'];
        yield 'duplicate build separator' => ['1.2.3+build+1'];
        yield 'underscore' => ['1.2.3-alpha_beta'];
        yield 'non-ASCII identifier' => ['1.2.3-béta'];
        yield 'leading whitespace' => [' 1.2.3'];
        yield 'trailing whitespace' => ['1.2.3 '];
        yield 'trailing newline' => ["1.2.3\n"];
        yield 'null byte' => ["1.2.3\0"];
        yield 'constraint expression' => ['^1.2.3'];
        yield 'development branch' => ['dev-main'];
        yield 'over maximum bytes' => [
            '1.0.0+' . str_repeat('a', 251),
        ];
    }

    #[DataProvider('orderedVersions')]
    public function testPrecedenceIsOrderedAndAntisymmetric(
        string $lower,
        string $higher,
    ): void {
        $first = new ModuleVersion($lower);
        $second = new ModuleVersion($higher);

        self::assertSame(-1, $first->compare($second));
        self::assertSame(1, $second->compare($first));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function orderedVersions(): iterable
    {
        yield 'major' => ['1.99.99', '2.0.0'];
        yield 'minor' => ['1.9.99', '1.10.0'];
        yield 'patch' => ['1.0.9', '1.0.10'];
        yield 'prerelease before stable' => ['1.0.0-rc.9', '1.0.0'];
        yield 'numeric prerelease' => ['1.0.0-2', '1.0.0-10'];
        yield 'numeric before nonnumeric' => ['1.0.0-99', '1.0.0-alpha'];
        yield 'ASCII lexical ordering' => ['1.0.0-Beta', '1.0.0-alpha'];
        yield 'additional prerelease identifier' => [
            '1.0.0-alpha',
            '1.0.0-alpha.0',
        ];
        yield 'core before prerelease status' => [
            '1.0.0',
            '2.0.0-alpha',
        ];
        yield 'large core numbers' => [
            '999999999999999999999999999999.0.0',
            '1000000000000000000000000000000.0.0',
        ];
        yield 'large prerelease numbers' => [
            '1.0.0-999999999999999999999999999999',
            '1.0.0-1000000000000000000000000000000',
        ];
        yield 'same-length large numbers' => [
            '999999999999999999999999999998.0.0',
            '999999999999999999999999999999.0.0',
        ];
    }

    #[DataProvider('equalPrecedenceVersions')]
    public function testBuildMetadataDoesNotChangePrecedence(
        string $left,
        string $right,
    ): void {
        $first = new ModuleVersion($left);
        $second = new ModuleVersion($right);

        self::assertSame(0, $first->compare($second));
        self::assertSame(0, $second->compare($first));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function equalPrecedenceVersions(): iterable
    {
        yield 'stable builds' => ['1.2.3+first', '1.2.3+second'];
        yield 'build versus no build' => ['1.2.3', '1.2.3+001'];
        yield 'prerelease builds' => [
            '1.2.3-rc.1+first',
            '1.2.3-rc.1+second',
        ];
    }

    public function testPrereleaseSequenceHasConsistentPrecedence(): void
    {
        $values = [
            '1.0.0-alpha',
            '1.0.0-alpha.1',
            '1.0.0-alpha.beta',
            '1.0.0-beta',
            '1.0.0-beta.2',
            '1.0.0-beta.11',
            '1.0.0-rc.1',
            '1.0.0',
        ];

        foreach ($values as $leftIndex => $left) {
            foreach ($values as $rightIndex => $right) {
                self::assertSame(
                    $leftIndex <=> $rightIndex,
                    new ModuleVersion($left)->compare(
                        new ModuleVersion($right),
                    ),
                );
            }
        }
    }
}
