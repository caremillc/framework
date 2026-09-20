<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Module\Exception\InvalidModuleVersionRangeException;
use Careminate\Module\ModuleVersion;
use Careminate\Module\ModuleVersionRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleVersionRangeTest extends TestCase
{
    #[DataProvider('membershipCases')]
    public function testRangeMembership(
        ModuleVersionRange $range,
        string $candidate,
        bool $expected,
    ): void {
        self::assertSame(
            $expected,
            $range->contains(new ModuleVersion($candidate)),
        );
    }

    /**
     * @return iterable<string, array{ModuleVersionRange, string, bool}>
     */
    public static function membershipCases(): iterable
    {
        $minimum = new ModuleVersion('1.0.0');
        $maximum = new ModuleVersion('2.0.0');

        $candidates = [
            '0.9.0',
            '1.0.0',
            '1.5.0',
            '2.0.0',
            '2.1.0',
        ];

        $ranges = [
            'any' => [
                ModuleVersionRange::any(),
                [true, true, true, true, true],
            ],
            'exact' => [
                ModuleVersionRange::exactly($minimum),
                [false, true, false, false, false],
            ],
            'inclusive minimum' => [
                ModuleVersionRange::atLeast($minimum),
                [false, true, true, true, true],
            ],
            'exclusive minimum' => [
                ModuleVersionRange::atLeast($minimum, inclusive: false),
                [false, false, true, true, true],
            ],
            'inclusive maximum' => [
                ModuleVersionRange::atMost($maximum),
                [true, true, true, true, false],
            ],
            'exclusive maximum' => [
                ModuleVersionRange::atMost($maximum, inclusive: false),
                [true, true, true, false, false],
            ],
            'default bounded' => [
                ModuleVersionRange::between($minimum, $maximum),
                [false, true, true, false, false],
            ],
            'closed bounded' => [
                ModuleVersionRange::between(
                    $minimum,
                    $maximum,
                    includeMaximum: true,
                ),
                [false, true, true, true, false],
            ],
            'open bounded' => [
                ModuleVersionRange::between(
                    $minimum,
                    $maximum,
                    includeMinimum: false,
                ),
                [false, false, true, false, false],
            ],
            'exclusive minimum inclusive maximum' => [
                ModuleVersionRange::between(
                    $minimum,
                    $maximum,
                    includeMinimum: false,
                    includeMaximum: true,
                ),
                [false, false, true, true, false],
            ],
        ];

        foreach ($ranges as $name => [$range, $expected]) {
            foreach ($candidates as $index => $candidate) {
                yield $name . ' / ' . $candidate => [
                    $range,
                    $candidate,
                    $expected[$index],
                ];
            }
        }
    }

    #[DataProvider('invalidRanges')]
    public function testInvalidBoundsAreRejected(
        string $minimum,
        string $maximum,
        bool $includeMinimum,
        bool $includeMaximum,
    ): void {
        $this->expectException(InvalidModuleVersionRangeException::class);

        ModuleVersionRange::between(
            new ModuleVersion($minimum),
            new ModuleVersion($maximum),
            $includeMinimum,
            $includeMaximum,
        );
    }

    /**
     * @return iterable<string, array{string, string, bool, bool}>
     */
    public static function invalidRanges(): iterable
    {
        yield 'reversed bounds' => ['2.0.0', '1.0.0', true, true];
        yield 'equal default bounds' => ['1.0.0', '1.0.0', true, false];
        yield 'equal open bounds' => ['1.0.0', '1.0.0', false, false];
        yield 'equal excluded minimum' => ['1.0.0', '1.0.0', false, true];
        yield 'build metadata does not separate bounds' => [
            '1.0.0+first',
            '1.0.0+second',
            true,
            false,
        ];
        yield 'stable minimum above prerelease maximum' => [
            '1.0.0',
            '1.0.0-rc.1',
            true,
            true,
        ];
    }

    public function testEqualInclusiveBoundsFormAnExactPrecedenceRange(): void
    {
        $range = ModuleVersionRange::between(
            new ModuleVersion('1.0.0+first'),
            new ModuleVersion('1.0.0+second'),
            includeMaximum: true,
        );

        self::assertTrue($range->contains(new ModuleVersion('1.0.0')));
        self::assertTrue($range->contains(new ModuleVersion('1.0.0+third')));
        self::assertFalse($range->contains(new ModuleVersion('1.0.0-rc.1')));
        self::assertFalse($range->contains(new ModuleVersion('1.0.1')));
    }

    public function testExactMatchingIgnoresBuildMetadata(): void
    {
        $range = ModuleVersionRange::exactly(
            new ModuleVersion('1.2.3+build.1'),
        );

        self::assertTrue($range->contains(new ModuleVersion('1.2.3')));
        self::assertTrue(
            $range->contains(new ModuleVersion('1.2.3+build.2')),
        );
        self::assertFalse($range->contains(new ModuleVersion('1.2.4')));
    }

    public function testPrereleasesParticipateWithoutAStabilityFilter(): void
    {
        $range = ModuleVersionRange::between(
            new ModuleVersion('1.0.0'),
            new ModuleVersion('2.0.0'),
        );

        self::assertFalse($range->contains(new ModuleVersion('1.0.0-rc.1')));
        self::assertTrue($range->contains(new ModuleVersion('1.1.0-alpha')));
        self::assertTrue($range->contains(new ModuleVersion('2.0.0-rc.1')));
        self::assertFalse($range->contains(new ModuleVersion('2.0.0')));
    }

    public function testPrereleaseUpperBoundCanExcludeTheNextReleaseSeries(): void
    {
        $range = ModuleVersionRange::between(
            new ModuleVersion('1.0.0'),
            new ModuleVersion('2.0.0-0'),
        );

        self::assertTrue($range->contains(new ModuleVersion('1.99.0')));
        self::assertFalse($range->contains(new ModuleVersion('2.0.0-0')));
        self::assertFalse($range->contains(new ModuleVersion('2.0.0-alpha')));
        self::assertFalse($range->contains(new ModuleVersion('2.0.0')));
    }

    public function testLargeNumericBoundsDoNotRequireIntegerConversion(): void
    {
        $range = ModuleVersionRange::between(
            new ModuleVersion('999999999999999999999999999998.0.0'),
            new ModuleVersion('1000000000000000000000000000000.0.0'),
        );

        self::assertTrue(
            $range->contains(
                new ModuleVersion('999999999999999999999999999999.0.0'),
            ),
        );
        self::assertFalse(
            $range->contains(
                new ModuleVersion('1000000000000000000000000000000.0.0'),
            ),
        );
    }

    public function testBoundsRemainAvailableForStructuredDiagnostics(): void
    {
        $minimum = new ModuleVersion('1.0.0');
        $maximum = new ModuleVersion('2.0.0');

        $range = ModuleVersionRange::between(
            $minimum,
            $maximum,
            includeMinimum: false,
            includeMaximum: true,
        );

        self::assertSame($minimum, $range->minimum);
        self::assertSame($maximum, $range->maximum);
        self::assertFalse($range->includeMinimum);
        self::assertTrue($range->includeMaximum);
    }

    public function testUnboundedRangeIncludesPrereleases(): void
    {
        $range = ModuleVersionRange::any();

        self::assertNull($range->minimum);
        self::assertNull($range->maximum);
        self::assertFalse($range->includeMinimum);
        self::assertFalse($range->includeMaximum);
        self::assertTrue($range->contains(new ModuleVersion('0.0.0-0')));
        self::assertTrue($range->contains(new ModuleVersion('999.999.999')));
    }
}
