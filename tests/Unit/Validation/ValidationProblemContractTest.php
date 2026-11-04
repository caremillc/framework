<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Validation;

use Careminate\Http\Exception\ValidationProblemResponseFactory;
use Careminate\Validation\Exception\ValidationException;
use Careminate\Validation\ValidationResult;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidationProblemContractTest extends TestCase
{
    public function testValidationFailureCarriesCodesWithoutInputValues(): void
    {
        $exception = new ValidationException(
            new ValidationResult(
                [],
                ['title' => ['required']],
            ),
        );

        self::assertSame(
            422,
            $exception->getCode(),
        );

        self::assertSame(
            'Input validation failed.',
            $exception->getMessage(),
        );

        self::assertSame(
            ['title' => ['required']],
            $exception->errors(),
        );
    }

    public function testValidResultCannotBecomeValidationException(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new ValidationException(
            new ValidationResult(
                ['title' => 'valid'],
                [],
            ),
        );
    }

    public function testUnknownFieldMarkerIsAcceptedWithoutFieldNames(): void
    {
        $exception = new ValidationException(
            new ValidationResult(
                [],
                ['_input' => ['unknown_fields']],
            ),
        );

        self::assertSame(
            ['_input' => ['unknown_fields']],
            $exception->errors(),
        );
    }

    public function testNestedIndexedErrorPathsCanBeRepresented(): void
    {
        $exception = new ValidationException(
            new ValidationResult(
                [],
                [
                    'tasks[12].title' => ['required'],
                ],
            ),
        );

        self::assertSame(
            [
                'tasks[12].title' => ['required'],
            ],
            $exception->errors(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedFieldCodes(): iterable
    {
        yield 'required' => ['required'];
        yield 'type' => ['type'];
        yield 'encoding' => ['encoding'];
        yield 'blank' => ['blank'];
        yield 'min_length' => ['min_length'];
        yield 'max_length' => ['max_length'];
        yield 'min' => ['min'];
        yield 'max' => ['max'];
    }

    #[DataProvider('acceptedFieldCodes')]
    public function testDocumentedFieldCodesAreAccepted(
        string $code,
    ): void {
        $exception = new ValidationException(
            new ValidationResult(
                [],
                [
                    'field' => [$code],
                ],
            ),
        );

        self::assertSame(
            [
                'field' => [$code],
            ],
            $exception->errors(),
        );
    }

    /**
     * @return iterable<
     *     string,
     *     array{array<string, list<string>>}
     * >
     */
    public static function invalidErrorShapes(): iterable
    {
        yield 'empty error list' => [
            [
                'field' => [],
            ],
        ];

        yield 'unrecognized code' => [
            [
                'field' => ['secret-value'],
            ],
        ];

        yield 'unrecognized code after valid code' => [
            [
                'field' => ['type', 'secret-value'],
            ],
        ];

        yield 'unknown_fields on named field' => [
            [
                'field' => ['unknown_fields'],
            ],
        ];

        yield 'invalid request-level error' => [
            [
                '_input' => ['required'],
            ],
        ];

        yield 'multiple request-level codes' => [
            [
                '_input' => [
                    'unknown_fields',
                    'unknown_fields',
                ],
            ],
        ];

        yield 'hyphenated field name' => [
            [
                'user-name' => ['type'],
            ],
        ];

        yield 'leading digit field name' => [
            [
                '1title' => ['type'],
            ],
        ];

        yield 'untrusted Unicode field name' => [
            [
                '🔑' => ['type'],
            ],
        ];

        yield 'field path containing quotes' => [
            [
                'field"private' => ['type'],
            ],
        ];

        yield 'field path over 256 bytes' => [
            [
                str_repeat('a', 257) => ['type'],
            ],
        ];

        yield 'empty array member index' => [
            [
                'items[].name' => ['type'],
            ],
        ];
    }

    /**
     * @param array<string, list<string>> $errors
     */
    #[DataProvider('invalidErrorShapes')]
    public function testMalformedErrorMapsAreRejectedBeforeRendering(
        array $errors,
    ): void {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new ValidationException(
            new ValidationResult(
                [],
                $errors,
            ),
        );
    }

    public function testProblemFactoryPreservesSecurityHeaders(): void
    {
        $exception = new ValidationException(
            new ValidationResult(
                [],
                [
                    'title' => ['blank'],
                ],
            ),
        );

        $response = new ValidationProblemResponseFactory()
            ->create($exception);

        self::assertSame(
            422,
            $response->status,
        );

        self::assertSame(
            'application/problem+json',
            $response->header('Content-Type'),
        );

        self::assertSame(
            'no-store',
            $response->header('Cache-Control'),
        );

        self::assertSame(
            'nosniff',
            $response->header('X-Content-Type-Options'),
        );

        self::assertSame(
            [
                'type' => 'about:blank',
                'title' => 'Unprocessable Content',
                'status' => 422,
                'errors' => [
                    'title' => ['blank'],
                ],
            ],
            json_decode(
                $response->body,
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testProblemFactoryNeverIncludesValidatedOrRawValues(): void
    {
        $exception = new ValidationException(
            new ValidationResult(
                [
                    'title' => 'private-draft',
                ],
                [
                    'priority' => ['max'],
                ],
            ),
        );

        $response = new ValidationProblemResponseFactory()
            ->create($exception);

        self::assertSame(
            422,
            $response->status,
        );

        self::assertStringNotContainsString(
            'private-draft',
            $response->body,
        );

        $decoded = json_decode(
            $response->body,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($decoded);

        self::assertSame(
            [
                'priority' => ['max'],
            ],
            $decoded['errors'],
        );
    }

    public function testProblemFactoryEncodesMultipleErrorsDeterministically(): void
    {
        $errors = [
            '_input' => ['unknown_fields'],
            'title' => ['blank', 'min_length'],
            'priority' => ['max'],
        ];

        $exception = new ValidationException(
            new ValidationResult(
                [],
                $errors,
            ),
        );

        $response = new ValidationProblemResponseFactory()
            ->create($exception);

        self::assertSame(
            $errors,
            $exception->errors(),
        );

        self::assertSame(
            422,
            $response->status,
        );

        self::assertStringContainsString(
            '"status":422',
            $response->body,
        );

        $decoded = json_decode(
            $response->body,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($decoded);

        self::assertSame(
            $errors,
            $decoded['errors'],
        );
    }
}
