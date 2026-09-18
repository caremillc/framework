<?php

declare(strict_types=1);

namespace Careminate\Container\Compilation\Internal;

use Careminate\Container\Compilation\Exception\ArtifactException;
use Careminate\Container\Compilation\Exception\PortableValueException;

/**
 * @internal
 */
final class ArtifactEnvelopeCodec
{
    private const int SCHEMA_VERSION = 1;

    private const string COMPILER_VERSION = 'careminate-definitions-v1';

    private const int MAX_BUILD_BYTES = 256;

    public function encode(string $payload, string $buildId): string
    {
        $this->validateBuildId($buildId);

        $envelope = [
            'schema' => self::SCHEMA_VERSION,
            'compiler' => self::COMPILER_VERSION,
            'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'integer_size' => PHP_INT_SIZE,
            'build' => $buildId,
            'fingerprint' => $this->fingerprint($payload),
            'payload' => $payload,
        ];

        try {
            return new PortableValueCodec()->encode($envelope);
        } catch (PortableValueException $previous) {
            throw new ArtifactException(
                message: 'The artifact envelope could not be encoded.',
                previous: $previous,
            );
        }
    }

    public function decode(
        string $artifact,
        string $expectedBuildId,
        string $expectedFingerprint,
    ): string {
        $this->validateBuildId($expectedBuildId);
        $this->validateFingerprint($expectedFingerprint);

        try {
            $envelope = new PortableValueCodec()->decode($artifact);
        } catch (PortableValueException $previous) {
            throw new ArtifactException(
                message: 'The artifact envelope could not be decoded.',
                previous: $previous,
            );
        }

        if (!is_array($envelope)) {
            throw new ArtifactException(
                'The artifact envelope must contain a field map.',
            );
        }

        $keys = array_keys($envelope);
        sort($keys, SORT_STRING);

        if ($keys !== [
            'build',
            'compiler',
            'fingerprint',
            'integer_size',
            'payload',
            'php',
            'schema',
        ]) {
            throw new ArtifactException(
                'The artifact envelope has an invalid field set.',
            );
        }

        if ($envelope['schema'] !== self::SCHEMA_VERSION) {
            throw new ArtifactException(
                'The artifact schema version is incompatible.',
            );
        }

        if ($envelope['compiler'] !== self::COMPILER_VERSION) {
            throw new ArtifactException(
                'The artifact compiler version is incompatible.',
            );
        }

        if (
            $envelope['php'] !== PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION
            || $envelope['integer_size'] !== PHP_INT_SIZE
        ) {
            throw new ArtifactException(
                'The artifact runtime target is incompatible.',
            );
        }

        if ($envelope['build'] !== $expectedBuildId) {
            throw new ArtifactException(
                'The artifact belongs to a different application build.',
            );
        }

        $payload = $envelope['payload'];
        $fingerprint = $envelope['fingerprint'];

        if (!is_string($payload) || !is_string($fingerprint)) {
            throw new ArtifactException(
                'The artifact payload or fingerprint has an invalid type.',
            );
        }

        $this->validateFingerprint($fingerprint);

        if (!hash_equals($fingerprint, $this->fingerprint($payload))) {
            throw new ArtifactException(
                'The artifact payload fingerprint does not match.',
            );
        }

        if (!hash_equals($expectedFingerprint, $fingerprint)) {
            throw new ArtifactException(
                'The artifact does not match the expected definition fingerprint.',
            );
        }

        return $payload;
    }

    public function fingerprint(string $payload): string
    {
        return hash('sha256', $payload);
    }

    private function validateBuildId(string $buildId): void
    {
        if ($buildId === '' || strlen($buildId) > self::MAX_BUILD_BYTES) {
            throw new ArtifactException(
                'The application build identifier must contain 1 to 256 bytes.',
            );
        }
    }

    private function validateFingerprint(string $fingerprint): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $fingerprint) !== 1) {
            throw new ArtifactException(
                'The artifact fingerprint must be a lowercase SHA-256 digest.',
            );
        }
    }
}
