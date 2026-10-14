<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Flint\Http\JsonObjectDecoder;
use PHPUnit\Framework\TestCase;

final class JsonObjectDecoderNumberTest extends TestCase
{
    public function testDefaultPreservesLargeIntegerDigitsAsString(): void
    {
        $document = JsonObjectDecoder::decode(
            '{"value":123456789012345678901234567890}',
        );

        self::assertSame(
            '123456789012345678901234567890',
            $document->value,
        );
    }

    public function testConversionCanBeDisabledForTypeValidation(): void
    {
        $document = JsonObjectDecoder::decode(
            '{"value":123456789012345678901234567890}',
            bigIntegersAsStrings: false,
        );

        self::assertIsFloat($document->value);
    }

    public function testQuotedDigitsRemainAStringWhenConversionIsDisabled(): void
    {
        $document = JsonObjectDecoder::decode(
            '{"value":"123456789012345678901234567890"}',
            bigIntegersAsStrings: false,
        );

        self::assertSame(
            '123456789012345678901234567890',
            $document->value,
        );
    }
}
