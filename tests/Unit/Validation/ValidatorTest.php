<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Validation;

use Careminate\Validation\Validator;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testValidFieldsAreReturnedWithoutCoercion(): void
    {
        $input = [
            'title' => 'Launch',
            'priority' => 3,
            'active' => false,
        ];

        $result = new Validator()->validate(
            $input,
            self::schema(),
        );

        self::assertTrue($result->isValid());
        self::assertSame([], $result->errors());
        self::assertSame($input, $result->validated());
    }

    public function testRequiredMissingFieldIsReported(): void
    {
        $result = new Validator()->validate(
            [],
            self::schema(),
        );

        self::assertFalse($result->isValid());

        self::assertSame(
            ['title' => ['required']],
            $result->errors(),
        );
    }

    public function testOptionalFieldsAreNotInserted(): void
    {
        $result = new Validator()->validate(
            ['title' => 'Task'],
            self::schema(),
        );

        self::assertTrue($result->isValid());

        self::assertSame(
            ['title' => 'Task'],
            $result->validated(),
        );
    }

    public function testNullablePresentFieldIsPreserved(): void
    {
        $result = new Validator()->validate(
            [
                'title' => 'Task',
                'description' => null,
            ],
            self::schema(),
        );

        self::assertTrue($result->isValid());

        self::assertSame(
            [
                'title' => 'Task',
                'description' => null,
            ],
            $result->validated(),
        );
    }

    public function testNonNullableNullIsRejected(): void
    {
        $result = new Validator()->validate(
            ['title' => null],
            self::schema(),
        );

        self::assertSame(
            ['title' => ['type']],
            $result->errors(),
        );
    }

    public function testNullableRequiredFieldMustStillBePresent(): void
    {
        $schema = [
            'name' => [
                'type' => 'string',
                'required' => true,
                'nullable' => true,
            ],
        ];

        $validator = new Validator();

        self::assertSame(
            ['name' => ['required']],
            $validator->validate([], $schema)->errors(),
        );

        self::assertSame(
            ['name' => null],
            $validator->validate(
                ['name' => null],
                $schema,
            )->validated(),
        );
    }

    /**
     * @return iterable<string, array{
     *     string,
     *     array<string, mixed>,
     *     list<string>
     * }>
     */
    public static function stringCases(): iterable
    {
        yield 'valid exact bound' => [
            'ab',
            ['minLength' => 2, 'maxLength' => 2],
            [],
        ];

        yield 'short' => [
            'a',
            ['minLength' => 2],
            ['min_length'],
        ];

        yield 'long' => [
            'abc',
            ['maxLength' => 2],
            ['max_length'],
        ];

        yield 'empty not blank' => [
            '',
            ['notBlank' => true],
            ['blank'],
        ];

        yield 'spaces not blank' => [
            '  ',
            ['notBlank' => true],
            ['blank'],
        ];

        yield 'Unicode space not blank' => [
            "\u{00A0}",
            ['notBlank' => true],
            ['blank'],
        ];

        yield 'empty allowed' => [
            '',
            [],
            [],
        ];

        yield 'Unicode single code point' => [
            'é',
            ['minLength' => 1, 'maxLength' => 1],
            [],
        ];

        yield 'emoji counts code points' => [
            '👩‍💻',
            ['maxLength' => 2],
            ['max_length'],
        ];

        yield 'combining marks count separately' => [
            "e\u{0301}",
            ['minLength' => 2, 'maxLength' => 2],
            [],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $expectedErrors
     */
    #[DataProvider('stringCases')]
    public function testStringRules(
        string $value,
        array $options,
        array $expectedErrors,
    ): void {
        $result = new Validator()->validate(
            ['field' => $value],
            [
                'field' => [
                    'type' => 'string',
                    ...$options,
                ],
            ],
        );

        if ($expectedErrors === []) {
            self::assertSame(
                ['field' => $value],
                $result->validated(),
            );
        } else {
            self::assertSame(
                ['field' => $expectedErrors],
                $result->errors(),
            );
        }
    }

    public function testInvalidUtf8IsRejectedWithoutEchoingInput(): void
    {
        $result = new Validator()->validate(
            ['field' => "\xFFprivate"],
            ['field' => ['type' => 'string']],
        );

        self::assertSame(
            ['field' => ['encoding']],
            $result->errors(),
        );
    }

    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function integerCases(): iterable
    {
        yield 'minimum inclusive' => [1, []];
        yield 'maximum inclusive' => [5, []];
        yield 'below minimum' => [0, ['min']];
        yield 'above maximum' => [6, ['max']];
        yield 'numeric string not coerced' => ['3', ['type']];
        yield 'float not coerced' => [3.0, ['type']];
        yield 'boolean not coerced' => [true, ['type']];
    }

    /** @param list<string> $expectedErrors */
    #[DataProvider('integerCases')]
    public function testIntegerRules(
        mixed $value,
        array $expectedErrors,
    ): void {
        $result = new Validator()->validate(
            ['level' => $value],
            [
                'level' => [
                    'type' => 'integer',
                    'min' => 1,
                    'max' => 5,
                ],
            ],
        );

        if ($expectedErrors === []) {
            self::assertSame(
                ['level' => $value],
                $result->validated(),
            );
        } else {
            self::assertSame(
                ['level' => $expectedErrors],
                $result->errors(),
            );
        }
    }

    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function booleanCases(): iterable
    {
        yield 'true accepted' => [true, []];
        yield 'false accepted' => [false, []];
        yield 'integer rejected' => [1, ['type']];
        yield 'string rejected' => ['false', ['type']];
    }

    /** @param list<string> $expectedErrors */
    #[DataProvider('booleanCases')]
    public function testBooleanRules(
        mixed $value,
        array $expectedErrors,
    ): void {
        $result = new Validator()->validate(
            ['active' => $value],
            ['active' => ['type' => 'boolean']],
        );

        if ($expectedErrors === []) {
            self::assertSame(
                ['active' => $value],
                $result->validated(),
            );
        } else {
            self::assertSame(
                ['active' => $expectedErrors],
                $result->errors(),
            );
        }
    }

    public function testUnexpectedFieldsAreRejectedByDefault(): void
    {
        $result = new Validator()->validate(
            [
                'title' => 'Task',
                'is_admin' => true,
            ],
            self::schema(),
        );

        self::assertSame(
            ['_input' => ['unknown_fields']],
            $result->errors(),
        );

        self::assertFalse($result->isValid());
    }

    public function testUnknownNumericFieldNamesAreRejected(): void
    {
        $result = new Validator()->validate(
            [
                'title' => 'Task',
                5 => 'secret',
            ],
            self::schema(),
        );

        self::assertSame(
            ['_input' => ['unknown_fields']],
            $result->errors(),
        );
    }

    public function testUnknownFieldsCanBeIgnoredButAreNotReturned(): void
    {
        $result = new Validator()->validate(
            [
                'title' => 'Task',
                'is_admin' => true,
            ],
            self::schema(),
            rejectUnknown: false,
        );

        self::assertSame(
            ['title' => 'Task'],
            $result->validated(),
        );
    }

    public function testSeveralFieldErrorsAreCollectedWithoutInputValues(): void
    {
        $result = new Validator()->validate(
            [
                'title' => '  ',
                'priority' => 100,
                'active' => 'secret',
                'token' => 'secret',
            ],
            self::schema(),
        );

        self::assertSame(
            [
                '_input' => ['unknown_fields'],
                'title' => ['blank'],
                'priority' => ['max'],
                'active' => ['type'],
            ],
            $result->errors(),
        );

        $errorJson = json_encode(
            $result->errors(),
            JSON_THROW_ON_ERROR,
        );

        self::assertStringNotContainsString(
            'secret',
            $errorJson,
        );

        self::assertStringNotContainsString(
            'token',
            $errorJson,
        );
    }

    public function testInvalidResultDoesNotExposePartialValidatedData(): void
    {
        $result = new Validator()->validate(
            [
                'title' => 'Task',
                'priority' => 'invalid',
            ],
            self::schema(),
        );

        self::assertFalse($result->isValid());

        $this->expectException(
            LogicException::class,
        );

        $result->validated();
    }

    public function testTheSameValidatorIsSafeForRepeatedIndependentCalls(): void
    {
        $validator = new Validator();

        self::assertSame(
            ['title' => ['required']],
            $validator->validate(
                [],
                self::schema(),
            )->errors(),
        );

        self::assertSame(
            ['title' => 'Next'],
            $validator->validate(
                ['title' => 'Next'],
                self::schema(),
            )->validated(),
        );
    }

    /**
     * @return iterable<string, array{
     *     array<string, array<string, mixed>>
     * }>
     */
    public static function invalidSchemaCases(): iterable
    {
        yield 'unknown field type' => [
            ['field' => ['type' => 'object']],
        ];

        yield 'missing field type' => [
            ['field' => ['required' => true]],
        ];

        yield 'invalid field name' => [
            ['bad-name' => ['type' => 'string']],
        ];

        yield 'reserved input field name' => [
            ['_input' => ['type' => 'string']],
        ];

        yield 'oversized field name' => [
            [
                str_repeat('a', 65) => [
                    'type' => 'string',
                ],
            ],
        ];

        yield 'unknown rule' => [
            [
                'field' => [
                    'type' => 'string',
                    'regex' => 'x',
                ],
            ],
        ];

        yield 'flag not boolean' => [
            [
                'field' => [
                    'type' => 'string',
                    'required' => 'yes',
                ],
            ],
        ];

        yield 'nullable not boolean' => [
            [
                'field' => [
                    'type' => 'string',
                    'nullable' => 1,
                ],
            ],
        ];

        yield 'notBlank not boolean' => [
            [
                'field' => [
                    'type' => 'string',
                    'notBlank' => 'yes',
                ],
            ],
        ];

        yield 'length not integer' => [
            [
                'field' => [
                    'type' => 'string',
                    'minLength' => '2',
                ],
            ],
        ];

        yield 'negative length' => [
            [
                'field' => [
                    'type' => 'string',
                    'minLength' => -1,
                ],
            ],
        ];

        yield 'inverted length bounds' => [
            [
                'field' => [
                    'type' => 'string',
                    'minLength' => 3,
                    'maxLength' => 2,
                ],
            ],
        ];

        yield 'inverted numeric bounds' => [
            [
                'field' => [
                    'type' => 'integer',
                    'min' => 4,
                    'max' => 3,
                ],
            ],
        ];

        yield 'integer with string rule' => [
            [
                'field' => [
                    'type' => 'integer',
                    'notBlank' => false,
                ],
            ],
        ];

        yield 'string with integer rule' => [
            [
                'field' => [
                    'type' => 'string',
                    'min' => 1,
                ],
            ],
        ];

        yield 'boolean with length rule' => [
            [
                'field' => [
                    'type' => 'boolean',
                    'maxLength' => 2,
                ],
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $schema
     */
    #[DataProvider('invalidSchemaCases')]
    public function testInvalidSchemasFailBeforeValidatingInput(
        array $schema,
    ): void {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new Validator()->validate(
            ['field' => 'value'],
            $schema,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function schema(): array
    {
        return [
            'title' => [
                'type' => 'string',
                'required' => true,
                'notBlank' => true,
                'maxLength' => 120,
            ],
            'priority' => [
                'type' => 'integer',
                'min' => 1,
                'max' => 10,
            ],
            'active' => [
                'type' => 'boolean',
            ],
            'description' => [
                'type' => 'string',
                'nullable' => true,
            ],
        ];
    }
}
