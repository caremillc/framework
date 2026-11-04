<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Validation;

use Careminate\Validation\Exception\ValidationException;
use Careminate\Validation\ExtendedRules;
use Careminate\Validation\Validator;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtendedRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{
     *     string,
     *     mixed,
     *     list<string|int|bool>,
     *     bool,
     *     list<string>
     * }>
     */
    public static function scalarEnumCases(): iterable
    {
        yield 'exact string' => [
            'string', 'viewer', ['viewer', 'editor'], false, [],
        ];
        yield 'different string' => [
            'string', 'owner', ['viewer', 'editor'], false, ['enum'],
        ];
        yield 'case sensitive' => [
            'string', 'VIEWER', ['viewer'], false, ['enum'],
        ];
        yield 'empty string permitted' => [
            'string', '', ['', 'ready'], false, [],
        ];
        yield 'Unicode exact' => [
            'string', 'مرحبا', ['مرحبا', 'Hello'], false, [],
        ];
        yield 'Unicode mismatch' => [
            'string', 'مرحبا', ['Hello'], false, ['enum'],
        ];
        yield 'integer exact' => [
            'integer', 0, [0, 2, 4], false, [],
        ];
        yield 'integer rejected' => [
            'integer', 3, [0, 2, 4], false, ['enum'],
        ];
        yield 'numeric string does not coerce' => [
            'integer', '2', [2], false, ['type'],
        ];
        yield 'bool true' => [
            'boolean', true, [true], false, [],
        ];
        yield 'bool false denied' => [
            'boolean', false, [true], false, ['enum'],
        ];
        yield 'bool false accepted' => [
            'boolean', false, [true, false], false, [],
        ];
        yield 'nullable null bypasses enum' => [
            'string', null, ['x'], true, [],
        ];
        yield 'nonnullable null rejected' => [
            'string', null, ['x'], false, ['type'],
        ];
    }

    /**
     * @param list<string|int|bool> $choices
     * @param list<string> $expected
     */
    #[DataProvider('scalarEnumCases')]
    public function testScalarEnumIsStrict(
        string $type,
        mixed $value,
        array $choices,
        bool $nullable,
        array $expected,
    ): void {
        $result = new Validator()->validate(
            ['value' => $value],
            [
                'value' => [
                    'type' => $type,
                    'enum' => $choices,
                    'nullable' => $nullable,
                ],
            ],
        );

        if ($expected === []) {
            self::assertSame(
                ['value' => $value],
                $result->validated(),
            );
        } else {
            self::assertSame(
                ['value' => $expected],
                $result->errors(),
            );
        }
    }

    /**
     * @return iterable<string, array{string, int, list<string>}>
     */
    public static function byteLengthCases(): iterable
    {
        yield 'ASCII exact' => ['abc', 3, []];
        yield 'ASCII too many' => ['abcd', 3, ['max_bytes']];
        yield 'accented Unicode fits' => ['éé', 4, []];
        yield 'accented Unicode exceeds' => ['éé', 3, ['max_bytes']];
        yield 'emoji fits' => ['💻', 4, []];
        yield 'emoji exceeds' => ['💻', 3, ['max_bytes']];
        yield 'empty fits zero' => ['', 0, []];
        yield 'nonempty violates zero' => ['a', 0, ['max_bytes']];
        yield 'combining sequence bytes' => [
            "e\u{0301}", 2, ['max_bytes'],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('byteLengthCases')]
    public function testMaximumUtf8Bytes(
        string $value,
        int $maxBytes,
        array $expected,
    ): void {
        $result = new Validator()->validate(
            ['value' => $value],
            [
                'value' => [
                    'type' => 'string',
                    'maxBytes' => $maxBytes,
                ],
            ],
        );

        if ($expected === []) {
            self::assertSame(
                ['value' => $value],
                $result->validated(),
            );
        } else {
            self::assertSame(
                ['value' => $expected],
                $result->errors(),
            );
        }
    }

    /**
     * @return iterable<string, array{
     *     string,
     *     list<mixed>,
     *     bool,
     *     list<string>
     * }>
     */
    public static function uniqueListCases(): iterable
    {
        yield 'distinct integers' => [
            'integer', [1, 2, 3], false, [],
        ];
        yield 'duplicate integers' => [
            'integer', [1, 2, 1], false, ['unique_items'],
        ];
        yield 'duplicate zero' => [
            'integer', [0, 0], false, ['unique_items'],
        ];
        yield 'case distinct strings' => [
            'string', ['a', 'A'], false, [],
        ];
        yield 'duplicate strings' => [
            'string', ['a', 'a'], false, ['unique_items'],
        ];
        yield 'Unicode duplicates' => [
            'string', ['é', 'é'], false, ['unique_items'],
        ];
        yield 'booleans distinct' => [
            'boolean', [true, false], false, [],
        ];
        yield 'booleans duplicate' => [
            'boolean', [true, true], false, ['unique_items'],
        ];
        yield 'empty list' => [
            'integer', [], false, [],
        ];
        yield 'single member' => [
            'integer', [7], false, [],
        ];
        yield 'two nullable nulls' => [
            'string', [null, null], true, ['unique_items'],
        ];
        yield 'one nullable null' => [
            'string', [null, 'abc'], true, [],
        ];
    }

    /**
     * @param list<mixed> $items
     * @param list<string> $expected
     */
    #[DataProvider('uniqueListCases')]
    public function testUniqueListItems(
        string $type,
        array $items,
        bool $nullable,
        array $expected,
    ): void {
        $result = new Validator()->validate(
            ['values' => $items],
            [
                'values' => [
                    'type' => 'array',
                    'uniqueItems' => true,
                    'items' => [
                        'type' => $type,
                        'nullable' => $nullable,
                    ],
                ],
            ],
        );

        if ($expected === []) {
            self::assertSame(
                ['values' => $items],
                $result->validated(),
            );
        } else {
            self::assertSame(
                ['values' => $expected],
                $result->errors(),
            );
        }
    }

    /**
     * @return iterable<string, array{
     *     array<string, array<string, mixed>>
     * }>
     */
    public static function invalidExtendedSchemas(): iterable
    {
        yield 'enum empty' => [
            ['value' => ['type' => 'string', 'enum' => []]],
        ];
        yield 'enum null' => [
            ['value' => ['type' => 'string', 'enum' => null]],
        ];
        yield 'enum not array' => [
            ['value' => ['type' => 'string', 'enum' => 'x']],
        ];
        yield 'enum associative' => [
            ['value' => [
                'type' => 'string',
                'enum' => ['x' => 'a'],
            ]],
        ];
        yield 'enum oversized' => [
            ['value' => [
                'type' => 'integer',
                'enum' => range(1, 65),
            ]],
        ];
        yield 'duplicate strings' => [
            ['value' => [
                'type' => 'string',
                'enum' => ['a', 'a'],
            ]],
        ];
        yield 'duplicate integers' => [
            ['value' => [
                'type' => 'integer',
                'enum' => [1, 1],
            ]],
        ];
        yield 'duplicate booleans' => [
            ['value' => [
                'type' => 'boolean',
                'enum' => [true, true],
            ]],
        ];
        yield 'wrong string choice' => [
            ['value' => [
                'type' => 'string',
                'enum' => ['a', 1],
            ]],
        ];
        yield 'wrong integer choice' => [
            ['value' => [
                'type' => 'integer',
                'enum' => [1, '2'],
            ]],
        ];
        yield 'wrong boolean choice' => [
            ['value' => [
                'type' => 'boolean',
                'enum' => [1],
            ]],
        ];
        yield 'null choice' => [
            ['value' => [
                'type' => 'string',
                'enum' => [null],
            ]],
        ];
        yield 'float choice' => [
            ['value' => [
                'type' => 'integer',
                'enum' => [1.0],
            ]],
        ];
        yield 'invalid UTF8 choice' => [
            ['value' => [
                'type' => 'string',
                'enum' => ["\xFF"],
            ]],
        ];
        yield 'oversized string choice' => [
            ['value' => [
                'type' => 'string',
                'enum' => [str_repeat('a', 257)],
            ]],
        ];
        yield 'enum on object' => [
            ['value' => [
                'type' => 'object',
                'fields' => [],
                'enum' => ['x'],
            ]],
        ];
        yield 'enum on array' => [
            ['value' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'enum' => ['x'],
            ]],
        ];
        yield 'negative maxBytes' => [
            ['value' => [
                'type' => 'string',
                'maxBytes' => -1,
            ]],
        ];
        yield 'float maxBytes' => [
            ['value' => [
                'type' => 'string',
                'maxBytes' => 3.0,
            ]],
        ];
        yield 'string maxBytes' => [
            ['value' => [
                'type' => 'string',
                'maxBytes' => '10',
            ]],
        ];
        yield 'maxBytes on integer' => [
            ['value' => [
                'type' => 'integer',
                'maxBytes' => 4,
            ]],
        ];
        yield 'uniqueItems on string' => [
            ['value' => [
                'type' => 'string',
                'uniqueItems' => true,
            ]],
        ];
        yield 'uniqueItems not boolean' => [
            ['value' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'uniqueItems' => 1,
            ]],
        ];
        yield 'uniqueItems on object elements' => [
            ['value' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'fields' => [],
                ],
                'uniqueItems' => true,
            ]],
        ];
        yield 'uniqueItems on nested arrays' => [
            ['value' => [
                'type' => 'array',
                'items' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'uniqueItems' => true,
            ]],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $schema
     */
    #[DataProvider('invalidExtendedSchemas')]
    public function testInvalidExtendedSchemaRejectedBeforeInput(
        array $schema,
    ): void {
        $this->expectException(
            InvalidArgumentException::class,
        );

        self::assertTrue(
            new Validator()->validate([], $schema)->isValid(),
        );
    }

    public function testStrictDuplicateKeysDistinguishTypes(): void
    {
        self::assertFalse(
            ExtendedRules::hasDuplicateScalarItems(
                [1, '1', true, 'true', null],
            ),
        );

        self::assertTrue(
            ExtendedRules::hasDuplicateScalarItems(
                [false, false],
            ),
        );
    }

    public function testDisabledUniquenessRetainsLegacyBehavior(): void
    {
        $result = new Validator()->validate(
            ['values' => [1, 1]],
            [
                'values' => [
                    'type' => 'array',
                    'uniqueItems' => false,
                    'items' => ['type' => 'integer'],
                ],
            ],
        );

        self::assertSame(
            ['values' => [1, 1]],
            $result->validated(),
        );
    }

    public function testConstraintErrorsCombineWithExistingBounds(): void
    {
        $result = new Validator()->validate(
            [
                'title' => 'abcdef',
                'priority' => 9,
            ],
            [
                'title' => [
                    'type' => 'string',
                    'maxLength' => 3,
                    'maxBytes' => 4,
                    'enum' => ['ready'],
                ],
                'priority' => [
                    'type' => 'integer',
                    'max' => 5,
                    'enum' => [1, 2],
                ],
            ],
        );

        self::assertSame(
            [
                'title' => [
                    'max_length',
                    'max_bytes',
                    'enum',
                ],
                'priority' => [
                    'max',
                    'enum',
                ],
            ],
            $result->errors(),
        );
    }

    public function testNestedConstraintsUseIndexedErrorPaths(): void
    {
        $result = new Validator()->validate(
            [
                'items' => [
                    ['state' => 'bad', 'label' => 'éé'],
                    ['state' => 'ok', 'label' => 'A'],
                ],
            ],
            [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'fields' => [
                            'state' => [
                                'type' => 'string',
                                'enum' => ['ok'],
                            ],
                            'label' => [
                                'type' => 'string',
                                'maxBytes' => 3,
                            ],
                        ],
                    ],
                ],
            ],
        );

        self::assertSame(
            [
                'items[0].state' => ['enum'],
                'items[0].label' => ['max_bytes'],
            ],
            $result->errors(),
        );

        self::assertSame(
            $result->errors(),
            new ValidationException($result)->errors(),
        );
    }

    public function testInvalidResultCannotExposePartialData(): void
    {
        $result = new Validator()->validate(
            [
                'name' => 'safe',
                'role' => 'root',
            ],
            [
                'name' => ['type' => 'string'],
                'role' => [
                    'type' => 'string',
                    'enum' => ['user'],
                ],
            ],
        );

        self::assertSame(
            ['role' => ['enum']],
            $result->errors(),
        );

        $this->expectException(LogicException::class);

        self::assertSame([], $result->validated());
    }

    public function testMalformedUtf8PreservesEncodingError(): void
    {
        $result = new Validator()->validate(
            ['value' => "\xFF"],
            [
                'value' => [
                    'type' => 'string',
                    'maxBytes' => 0,
                    'enum' => ['abc'],
                ],
            ],
        );

        self::assertSame(
            ['value' => ['encoding']],
            $result->errors(),
        );
    }

    public function testDuplicateAndInvalidMemberTypeAreBothReported(): void
    {
        $result = new Validator()->validate(
            ['values' => [1, '1', 1]],
            [
                'values' => [
                    'type' => 'array',
                    'uniqueItems' => true,
                    'items' => ['type' => 'integer'],
                ],
            ],
        );

        self::assertSame(
            [
                'values' => ['unique_items'],
                'values[1]' => ['type'],
            ],
            $result->errors(),
        );
    }

    public function testIndependentCallsDoNotShareErrors(): void
    {
        $validator = new Validator();

        $schema = [
            'role' => [
                'type' => 'string',
                'enum' => ['user'],
            ],
        ];

        self::assertSame(
            ['role' => ['enum']],
            $validator->validate(
                ['role' => 'admin'],
                $schema,
            )->errors(),
        );

        self::assertSame(
            ['role' => 'user'],
            $validator->validate(
                ['role' => 'user'],
                $schema,
            )->validated(),
        );
    }
}
