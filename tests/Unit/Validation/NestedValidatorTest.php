<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Validation;

use Careminate\Validation\Exception\ValidationException;
use Careminate\Validation\Validator;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NestedValidatorTest extends TestCase
{
    public function testExistingFlatValidationContractRemainsUnchanged(): void
    {
        $result = new Validator()->validate(
            ['title' => 'Task', 'priority' => 2, 'active' => false],
            [
                'title' => [
                    'type' => 'string',
                    'required' => true,
                    'notBlank' => true,
                ],
                'priority' => [
                    'type' => 'integer',
                    'min' => 1,
                    'max' => 4,
                ],
                'active' => ['type' => 'boolean'],
            ],
        );

        self::assertTrue($result->isValid());
        self::assertSame([], $result->errors());

        self::assertSame(
            ['title' => 'Task', 'priority' => 2, 'active' => false],
            $result->validated(),
        );
    }

    public function testValidNestedObjectsAndArraysReturnOnlySchemaFields(): void
    {
        $input = [
            'name' => 'Sprint',
            'profile' => [
                'email' => 'a@example.test',
                'active' => true,
            ],
            'tasks' => [
                ['title' => 'Build', 'priority' => 1],
                ['title' => 'Test', 'priority' => 2],
            ],
        ];

        $result = new Validator()->validate(
            $input,
            self::projectSchema(),
        );

        self::assertTrue($result->isValid());
        self::assertSame($input, $result->validated());
    }

    public function testRequiredChildFieldsUseDottedPaths(): void
    {
        $result = new Validator()->validate(
            ['profile' => ['active' => true]],
            [
                'profile' => [
                    'type' => 'object',
                    'fields' => [
                        'email' => [
                            'type' => 'string',
                            'required' => true,
                        ],
                        'active' => ['type' => 'boolean'],
                    ],
                ],
            ],
        );

        self::assertSame(
            ['profile.email' => ['required']],
            $result->errors(),
        );
    }

    public function testArrayMemberErrorsUseStableIndexedPaths(): void
    {
        $result = new Validator()->validate(
            [
                'tasks' => [
                    ['title' => 'Valid'],
                    [],
                    ['title' => 12],
                ],
            ],
            [
                'tasks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'fields' => [
                            'title' => [
                                'type' => 'string',
                                'required' => true,
                            ],
                        ],
                    ],
                ],
            ],
        );

        self::assertSame(
            [
                'tasks[1].title' => ['required'],
                'tasks[2].title' => ['type'],
            ],
            $result->errors(),
        );
    }

    public function testNestedUnknownFieldsNeverExposeUntrustedNames(): void
    {
        $result = new Validator()->validate(
            [
                'profile' => [
                    'email' => 'a@b.test',
                    'access_token' => 'SECRET',
                ],
            ],
            [
                'profile' => [
                    'type' => 'object',
                    'fields' => [
                        'email' => ['type' => 'string'],
                    ],
                ],
            ],
        );

        self::assertSame(
            ['profile._input' => ['unknown_fields']],
            $result->errors(),
        );

        $encoded = json_encode(
            $result->errors(),
            JSON_THROW_ON_ERROR,
        );

        self::assertStringNotContainsString(
            'access_token',
            $encoded,
        );

        self::assertStringNotContainsString(
            'SECRET',
            $encoded,
        );
    }

    public function testNestedUnknownFieldsCanBeIgnoredAndAreNeverReturned(): void
    {
        $result = new Validator()->validate(
            [
                'profile' => [
                    'email' => 'a@b.test',
                    'admin' => true,
                ],
                'other' => 'ignored',
            ],
            [
                'profile' => [
                    'type' => 'object',
                    'fields' => [
                        'email' => ['type' => 'string'],
                    ],
                ],
            ],
            rejectUnknown: false,
        );

        self::assertTrue($result->isValid());

        self::assertSame(
            ['profile' => ['email' => 'a@b.test']],
            $result->validated(),
        );
    }

    public function testListOfPrimitivesReturnsUnmodifiedValues(): void
    {
        $values = [2, 4, 6];

        $result = new Validator()->validate(
            ['ids' => $values],
            [
                'ids' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'integer',
                        'min' => 1,
                    ],
                ],
            ],
        );

        self::assertSame(
            ['ids' => $values],
            $result->validated(),
        );
    }

    public function testArraysOfArraysCanBeValidatedRecursively(): void
    {
        $result = new Validator()->validate(
            ['matrix' => [[1, 2], [3]]],
            [
                'matrix' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'items' => [
                            'type' => 'integer',
                            'min' => 1,
                        ],
                    ],
                ],
            ],
        );

        self::assertSame(
            ['matrix' => [[1, 2], [3]]],
            $result->validated(),
        );
    }

    public function testArrayOfArrayIndexErrorsHaveCorrectPaths(): void
    {
        $result = new Validator()->validate(
            ['matrix' => [[1, -5], []]],
            [
                'matrix' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'items' => [
                            'type' => 'integer',
                            'min' => 0,
                        ],
                    ],
                ],
            ],
        );

        self::assertSame(
            [
                'matrix[0][1]' => ['min'],
                'matrix[1]' => ['min_items'],
            ],
            $result->errors(),
        );
    }

    public function testNullableArrayItemsAcceptNullButNotWrongType(): void
    {
        $result = new Validator()->validate(
            ['values' => [1, null, 'nope']],
            [
                'values' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'integer',
                        'nullable' => true,
                    ],
                ],
            ],
        );

        self::assertSame(
            ['values[2]' => ['type']],
            $result->errors(),
        );
    }

    public function testEmptyContainersAreAcceptedByBothContainerRules(): void
    {
        $result = new Validator()->validate(
            [
                'profile' => [],
                'tasks' => [],
            ],
            [
                'profile' => [
                    'type' => 'object',
                    'fields' => [],
                ],
                'tasks' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
        );

        self::assertSame(
            ['profile' => [], 'tasks' => []],
            $result->validated(),
        );
    }

    public function testInvalidNestedResultCannotExposePartialData(): void
    {
        $result = new Validator()->validate(
            [
                'tasks' => [
                    ['title' => 'Good'],
                    ['title' => ''],
                ],
            ],
            [
                'tasks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'fields' => [
                            'title' => [
                                'type' => 'string',
                                'notBlank' => true,
                            ],
                        ],
                    ],
                ],
            ],
        );

        self::assertFalse($result->isValid());

        self::assertSame(
            ['tasks[1].title' => ['blank']],
            $result->errors(),
        );

        $this->expectException(LogicException::class);

        $result->validated();
    }

    public function testNestedErrorsPassValidationExceptionPathValidation(): void
    {
        $result = new Validator()->validate(
            [
                'tasks' => [
                    [
                        'title' => 42,
                        'secret_name' => 'sensitive',
                    ],
                ],
            ],
            [
                'tasks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'fields' => [
                            'title' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        );

        $exception = new ValidationException($result);

        self::assertSame(
            [
                'tasks[0]._input' => ['unknown_fields'],
                'tasks[0].title' => ['type'],
            ],
            $exception->errors(),
        );
    }

    public function testFreshValidationCallsDoNotShareErrors(): void
    {
        $validator = new Validator();

        $schema = [
            'profile' => [
                'type' => 'object',
                'fields' => [
                    'name' => [
                        'type' => 'string',
                        'required' => true,
                    ],
                ],
            ],
        ];

        self::assertSame(
            ['profile.name' => ['required']],
            $validator->validate(
                ['profile' => []],
                $schema,
            )->errors(),
        );

        self::assertSame(
            ['profile' => ['name' => 'Next']],
            $validator->validate(
                ['profile' => ['name' => 'Next']],
                $schema,
            )->validated(),
        );
    }

    public function testSchemaIsCheckedEvenIfCorrespondingFieldIsAbsent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Validator()->validate(
            [],
            [
                'optional' => [
                    'type' => 'object',
                    'fields' => [
                        'bad' => ['type' => 'not-supported'],
                    ],
                ],
            ],
        );
    }

    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function invalidObjectValues(): iterable
    {
        yield 'scalar string' => ['abc', ['type']];
        yield 'scalar integer' => [4, ['type']];
        yield 'boolean' => [true, ['type']];
        yield 'nonempty list' => [[1, 2], ['type']];
        yield 'nonempty string-key object accepted' => [
            ['name' => 'A'],
            [],
        ];
        yield 'empty object accepted' => [[], []];
    }

    /** @param list<string> $expected */
    #[DataProvider('invalidObjectValues')]
    public function testObjectShape(
        mixed $value,
        array $expected,
    ): void {
        $result = new Validator()->validate(
            ['profile' => $value],
            [
                'profile' => [
                    'type' => 'object',
                    'fields' => [
                        'name' => ['type' => 'string'],
                    ],
                ],
            ],
        );

        if ($expected === []) {
            self::assertSame(
                ['profile' => $value],
                $result->validated(),
            );
        } else {
            self::assertSame(
                ['profile' => $expected],
                $result->errors(),
            );
        }
    }

    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function arrayShapeCases(): iterable
    {
        yield 'integer not a list' => [1, ['type']];
        yield 'string not a list' => ['abc', ['type']];
        yield 'associative array not a list' => [
            ['a' => 1],
            ['type'],
        ];
        yield 'empty list accepted' => [[], []];
        yield 'nonempty list accepted' => [[1, 2], []];
    }

    /** @param list<string> $expected */
    #[DataProvider('arrayShapeCases')]
    public function testArrayShape(
        mixed $value,
        array $expected,
    ): void {
        $result = new Validator()->validate(
            ['ids' => $value],
            [
                'ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                ],
            ],
        );

        if ($expected === []) {
            self::assertSame(
                ['ids' => $value],
                $result->validated(),
            );
        } else {
            self::assertSame(
                ['ids' => $expected],
                $result->errors(),
            );
        }
    }

    /**
     * @return iterable<string, array{
     *     list<mixed>,
     *     int,
     *     int,
     *     list<string>
     * }>
     */
    public static function listSizeCases(): iterable
    {
        yield 'min inclusive' => [[1, 2], 2, 3, []];
        yield 'max inclusive' => [[1, 2, 3], 2, 3, []];
        yield 'below min' => [[1], 2, 3, ['min_items']];
        yield 'above max' => [
            [1, 2, 3, 4],
            2,
            3,
            ['max_items'],
        ];
        yield 'empty violates min' => [
            [],
            1,
            3,
            ['min_items'],
        ];
        yield 'empty allowed' => [[], 0, 3, []];
    }

    /**
     * @param list<mixed> $values
     * @param list<string> $expected
     */
    #[DataProvider('listSizeCases')]
    public function testListSizeRules(
        array $values,
        int $min,
        int $max,
        array $expected,
    ): void {
        $result = new Validator()->validate(
            ['ids' => $values],
            [
                'ids' => [
                    'type' => 'array',
                    'minItems' => $min,
                    'maxItems' => $max,
                    'items' => ['type' => 'integer'],
                ],
            ],
        );

        if ($expected === []) {
            self::assertSame(
                ['ids' => $values],
                $result->validated(),
            );
        } else {
            self::assertSame(
                ['ids' => $expected],
                $result->errors(),
            );
        }
    }

    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function scalarItemCases(): iterable
    {
        yield 'valid integer' => [1, []];
        yield 'integer under min' => [0, ['min']];
        yield 'integer over max' => [6, ['max']];
        yield 'string is not coerced' => ['3', ['type']];
        yield 'boolean is not coerced' => [true, ['type']];
        yield 'null is not accepted by default' => [
            null,
            ['type'],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('scalarItemCases')]
    public function testScalarItemRules(
        mixed $value,
        array $expected,
    ): void {
        $result = new Validator()->validate(
            ['ids' => [$value]],
            [
                'ids' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'integer',
                        'min' => 1,
                        'max' => 5,
                    ],
                ],
            ],
        );

        if ($expected === []) {
            self::assertSame(
                ['ids' => [$value]],
                $result->validated(),
            );
        } else {
            self::assertSame(
                ['ids[0]' => $expected],
                $result->errors(),
            );
        }
    }

    /**
     * @return iterable<string, array{
     *     array<string, array<string, mixed>>
     * }>
     */
    public static function invalidNestedSchemas(): iterable
    {
        yield 'object missing fields' => [
            ['a' => ['type' => 'object']],
        ];

        yield 'object fields not an array' => [
            ['a' => [
                'type' => 'object',
                'fields' => 'bad',
            ]],
        ];

        yield 'object unknown rule' => [
            ['a' => [
                'type' => 'object',
                'fields' => [],
                'min' => 1,
            ]],
        ];

        yield 'nested bad name' => [
            ['a' => [
                'type' => 'object',
                'fields' => [
                    'bad-name' => ['type' => 'string'],
                ],
            ]],
        ];

        yield 'nested reserved name' => [
            ['a' => [
                'type' => 'object',
                'fields' => [
                    '_input' => ['type' => 'string'],
                ],
            ]],
        ];

        yield 'nested invalid type' => [
            ['a' => [
                'type' => 'object',
                'fields' => [
                    'v' => ['type' => 'float'],
                ],
            ]],
        ];

        yield 'array missing items' => [
            ['a' => ['type' => 'array']],
        ];

        yield 'array items not array' => [
            ['a' => [
                'type' => 'array',
                'items' => 'bad',
            ]],
        ];

        yield 'array item missing type' => [
            ['a' => [
                'type' => 'array',
                'items' => ['nullable' => true],
            ]],
        ];

        yield 'array item unknown rule' => [
            ['a' => [
                'type' => 'array',
                'items' => [
                    'type' => 'integer',
                    'maxLength' => 3,
                ],
            ]],
        ];

        yield 'array item unknown type' => [
            ['a' => [
                'type' => 'array',
                'items' => ['type' => 'float'],
            ]],
        ];

        yield 'array min items negative' => [
            ['a' => [
                'type' => 'array',
                'minItems' => -1,
                'items' => ['type' => 'integer'],
            ]],
        ];

        yield 'array max items negative' => [
            ['a' => [
                'type' => 'array',
                'maxItems' => -1,
                'items' => ['type' => 'integer'],
            ]],
        ];

        yield 'array bounds inverted' => [
            ['a' => [
                'type' => 'array',
                'minItems' => 3,
                'maxItems' => 2,
                'items' => ['type' => 'integer'],
            ]],
        ];

        yield 'array bounds not integers' => [
            ['a' => [
                'type' => 'array',
                'maxItems' => '2',
                'items' => ['type' => 'integer'],
            ]],
        ];

        yield 'array items invalid nullable flag' => [
            ['a' => [
                'type' => 'array',
                'items' => [
                    'type' => 'integer',
                    'nullable' => 1,
                ],
            ]],
        ];

        yield 'object nested invalid bounds' => [
            ['a' => [
                'type' => 'object',
                'fields' => [
                    'v' => [
                        'type' => 'string',
                        'minLength' => 3,
                        'maxLength' => 2,
                    ],
                ],
            ]],
        ];

        yield 'object nested invalid flag' => [
            ['a' => [
                'type' => 'object',
                'fields' => [
                    'v' => [
                        'type' => 'boolean',
                        'required' => 'yes',
                    ],
                ],
            ]],
        ];
    }

    /** @param array<string, array<string, mixed>> $schema */
    #[DataProvider('invalidNestedSchemas')]
    public function testInvalidNestedSchemasAreRejectedBeforeReadingInput(
        array $schema,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new Validator()->validate([], $schema);
    }

    public function testSchemaDepthIsBounded(): void
    {
        $rule = ['type' => 'string'];

        for ($i = 0; $i < 18; ++$i) {
            $rule = [
                'type' => 'array',
                'items' => $rule,
            ];
        }

        $this->expectException(InvalidArgumentException::class);

        new Validator()->validate(
            [],
            ['deep' => $rule],
        );
    }

    public function testErrorPathLengthIsBoundedBeforeInputTraversal(): void
    {
        $rule = ['type' => 'string'];

        for ($i = 0; $i < 5; ++$i) {
            $rule = [
                'type' => 'object',
                'fields' => [
                    str_repeat('x', 64) => $rule,
                ],
            ];
        }

        $this->expectException(InvalidArgumentException::class);

        new Validator()->validate(
            [],
            ['root' => $rule],
        );
    }

    /** @return array<string, array<string, mixed>> */
    private static function projectSchema(): array
    {
        return [
            'name' => [
                'type' => 'string',
                'required' => true,
            ],
            'profile' => [
                'type' => 'object',
                'fields' => [
                    'email' => [
                        'type' => 'string',
                        'required' => true,
                    ],
                    'active' => [
                        'type' => 'boolean',
                    ],
                ],
            ],
            'tasks' => [
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => 10,
                'items' => [
                    'type' => 'object',
                    'fields' => [
                        'title' => [
                            'type' => 'string',
                            'required' => true,
                            'notBlank' => true,
                        ],
                        'priority' => [
                            'type' => 'integer',
                            'min' => 1,
                            'max' => 5,
                        ],
                    ],
                ],
            ],
        ];
    }
}
