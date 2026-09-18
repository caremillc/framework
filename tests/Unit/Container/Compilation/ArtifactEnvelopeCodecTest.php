<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container\Compilation;

use Careminate\Container\Compilation\Exception\ArtifactException;
use Careminate\Container\Compilation\Exception\PortableValueException;
use Careminate\Container\Compilation\Internal\ArtifactEnvelopeCodec;
use Careminate\Container\Compilation\Internal\PortableValueCodec;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArtifactEnvelopeCodecTest extends TestCase
{
    public function testPayloadRoundTripsExactly(): void
    {
        $codec = new ArtifactEnvelopeCodec();
        $payload = "definition-payload\0\xff";

        $artifact = $codec->encode($payload, 'build-1');

        self::assertSame(
            $payload,
            $codec->decode(
                $artifact,
                'build-1',
                $codec->fingerprint($payload),
            ),
        );

        self::assertSame($artifact, $codec->encode($payload, 'build-1'));
    }

    public function testEmptyPayloadIsAllowedAtTheEnvelopeBoundary(): void
    {
        $codec = new ArtifactEnvelopeCodec();

        self::assertSame(
            '',
            $codec->decode(
                $codec->encode('', 'build-1'),
                'build-1',
                $codec->fingerprint(''),
            ),
        );
    }

    public function testDifferentBuildIsRejected(): void
    {
        $codec = new ArtifactEnvelopeCodec();
        $artifact = $codec->encode('payload', 'build-1');

        $this->expectException(ArtifactException::class);
        $this->expectExceptionMessage(
            'The artifact belongs to a different application build.',
        );

        $codec->decode(
            $artifact,
            'build-2',
            $codec->fingerprint('payload'),
        );
    }

    public function testDifferentExpectedFingerprintIsRejected(): void
    {
        $codec = new ArtifactEnvelopeCodec();
        $artifact = $codec->encode('payload', 'build-1');

        $this->expectException(ArtifactException::class);
        $this->expectExceptionMessage(
            'The artifact does not match the expected definition fingerprint.',
        );

        $codec->decode(
            $artifact,
            'build-1',
            $codec->fingerprint('different payload'),
        );
    }

    public function testPayloadTamperingIsRejected(): void
    {
        $codec = new ArtifactEnvelopeCodec();
        $fields = $this->envelopeFields(
            $codec->encode('original', 'build-1'),
        );

        $fields['payload'] = 'modified';

        $this->expectException(ArtifactException::class);
        $this->expectExceptionMessage(
            'The artifact payload fingerprint does not match.',
        );

        $codec->decode(
            new PortableValueCodec()->encode($fields),
            'build-1',
            $codec->fingerprint('original'),
        );
    }

    public function testRecomputedEmbeddedHashCannotBypassExpectedFingerprint(): void
    {
        $codec = new ArtifactEnvelopeCodec();
        $fields = $this->envelopeFields(
            $codec->encode('original', 'build-1'),
        );

        $fields['payload'] = 'modified';
        $fields['fingerprint'] = $codec->fingerprint('modified');

        $this->expectException(ArtifactException::class);
        $this->expectExceptionMessage(
            'The artifact does not match the expected definition fingerprint.',
        );

        $codec->decode(
            new PortableValueCodec()->encode($fields),
            'build-1',
            $codec->fingerprint('original'),
        );
    }

    #[DataProvider('invalidFields')]
    public function testInvalidEnvelopeFieldsAreRejected(
        string $field,
        mixed $value,
    ): void {
        $codec = new ArtifactEnvelopeCodec();
        $fields = $this->envelopeFields(
            $codec->encode('payload', 'build-1'),
        );

        $fields[$field] = $value;

        $this->expectException(ArtifactException::class);

        $codec->decode(
            new PortableValueCodec()->encode($fields),
            'build-1',
            $codec->fingerprint('payload'),
        );
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidFields(): iterable
    {
        yield 'unknown schema' => ['schema', 999];
        yield 'schema wrong type' => ['schema', '1'];
        yield 'unknown compiler' => ['compiler', 'unknown'];
        yield 'different PHP target' => ['php', '0.0'];
        yield 'different integer size' => ['integer_size', 0];
        yield 'invalid build type' => ['build', null];
        yield 'invalid payload type' => ['payload', []];
        yield 'invalid fingerprint type' => ['fingerprint', false];
        yield 'invalid fingerprint text' => ['fingerprint', 'invalid'];
        yield 'unexpected field' => ['extra', null];
    }

    public function testMissingFieldIsRejected(): void
    {
        $codec = new ArtifactEnvelopeCodec();
        $fields = $this->envelopeFields(
            $codec->encode('payload', 'build-1'),
        );

        unset($fields['compiler']);

        $this->expectException(ArtifactException::class);

        $codec->decode(
            new PortableValueCodec()->encode($fields),
            'build-1',
            $codec->fingerprint('payload'),
        );
    }

    public function testFieldOrderingDoesNotAffectDecoding(): void
    {
        $codec = new ArtifactEnvelopeCodec();
        $fields = $this->envelopeFields(
            $codec->encode('payload', 'build-1'),
        );

        self::assertSame(
            'payload',
            $codec->decode(
                new PortableValueCodec()->encode(
                    array_reverse($fields, true),
                ),
                'build-1',
                $codec->fingerprint('payload'),
            ),
        );
    }

    public function testScalarEnvelopeIsRejected(): void
    {
        $codec = new ArtifactEnvelopeCodec();

        $this->expectException(ArtifactException::class);

        $codec->decode(
            new PortableValueCodec()->encode(null),
            'build-1',
            $codec->fingerprint('payload'),
        );
    }

    #[DataProvider('invalidBuildIds')]
    public function testInvalidBuildIdsAreRejectedInBothDirections(
        string $buildId,
    ): void {
        $codec = new ArtifactEnvelopeCodec();

        $encodingFailure = self::captureFailure(
            static fn (): string => $codec->encode('payload', $buildId),
        );

        self::assertSame(
            'The application build identifier must contain 1 to 256 bytes.',
            $encodingFailure->getMessage(),
        );

        $artifact = $codec->encode('payload', 'valid');

        $decodingFailure = self::captureFailure(
            static fn (): string => $codec->decode(
                $artifact,
                $buildId,
                $codec->fingerprint('payload'),
            ),
        );

        self::assertSame(
            'The application build identifier must contain 1 to 256 bytes.',
            $decodingFailure->getMessage(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBuildIds(): iterable
    {
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 257)];
    }

    public function testMaximumBuildIdLengthIsAccepted(): void
    {
        $codec = new ArtifactEnvelopeCodec();
        $buildId = str_repeat('a', 256);

        self::assertSame(
            'payload',
            $codec->decode(
                $codec->encode('payload', $buildId),
                $buildId,
                $codec->fingerprint('payload'),
            ),
        );
    }

    public function testInvalidExpectedFingerprintIsRejected(): void
    {
        $codec = new ArtifactEnvelopeCodec();

        $this->expectException(ArtifactException::class);

        $codec->decode(
            $codec->encode('payload', 'build-1'),
            'build-1',
            'not-a-digest',
        );
    }

    public function testMalformedDocumentPreservesItsCodecCause(): void
    {
        $codec = new ArtifactEnvelopeCodec();

        $failure = self::captureFailure(
            static fn (): string => $codec->decode(
                '[',
                'build-1',
                $codec->fingerprint('payload'),
            ),
        );

        self::assertInstanceOf(
            PortableValueException::class,
            $failure->getPrevious(),
        );

        self::assertSame(
            'The artifact envelope could not be decoded.',
            $failure->getMessage(),
        );
    }

    public function testOversizedArtifactIsRejected(): void
    {
        $codec = new ArtifactEnvelopeCodec();

        $failure = self::captureFailure(
            static fn (): string => $codec->decode(
                str_repeat(' ', 1048577),
                'build-1',
                $codec->fingerprint('payload'),
            ),
        );

        self::assertInstanceOf(
            PortableValueException::class,
            $failure->getPrevious(),
        );
    }

    public function testOversizedPayloadIsRejectedDuringEncoding(): void
    {
        $codec = new ArtifactEnvelopeCodec();

        $failure = self::captureFailure(
            static fn (): string => $codec->encode(
                str_repeat('a', 800000),
                'build-1',
            ),
        );

        self::assertInstanceOf(
            PortableValueException::class,
            $failure->getPrevious(),
        );
    }

    public function testFailureDoesNotExposeBuildIdOrPoisonTheCodec(): void
    {
        $codec = new ArtifactEnvelopeCodec();
        $artifact = $codec->encode('payload', 'private-build-marker');

        $failure = self::captureFailure(
            static fn (): string => $codec->decode(
                $artifact,
                'another-private-marker',
                $codec->fingerprint('payload'),
            ),
        );

        self::assertSame(
            'The artifact belongs to a different application build.',
            $failure->getMessage(),
        );
        self::assertNull($failure->getPrevious());

        self::assertSame(
            'payload',
            $codec->decode(
                $artifact,
                'private-build-marker',
                $codec->fingerprint('payload'),
            ),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function envelopeFields(string $artifact): array
    {
        $fields = new PortableValueCodec()->decode($artifact);

        self::assertIsArray($fields);

        return $fields;
    }

    /**
     * @param Closure(): mixed $operation
     */
    private static function captureFailure(
        Closure $operation,
    ): ArtifactException {
        try {
            $operation();
        } catch (ArtifactException $exception) {
            return $exception;
        }

        self::fail('The artifact operation must fail.');
    }
}
