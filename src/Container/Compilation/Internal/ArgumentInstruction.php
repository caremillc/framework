<?php

declare(strict_types=1);

namespace Careminate\Container\Compilation\Internal;

use Careminate\Container\Compilation\Exception\DefinitionException;

/**
 * @internal
 */
final readonly class ArgumentInstruction
{
    private function __construct(
        public string $parameter,
        public ArgumentAction $action,
        public string $target,
        public mixed $value,
    ) {
        if (
            preg_match(
                '/\A[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*\z/',
                $parameter,
            ) !== 1
        ) {
            throw new DefinitionException(
                'An argument instruction requires a valid parameter name.',
            );
        }
    }

    public static function literal(string $parameter, mixed $value): self
    {
        $codec = new PortableValueCodec();

        // Detach any references in the input before storing the literal.
        $snapshot = $codec->decode($codec->encode($value));

        return new self(
            $parameter,
            ArgumentAction::Literal,
            '',
            $snapshot,
        );
    }

    public static function service(string $parameter, string $identifier): self
    {
        self::validateTarget($identifier);

        return new self(
            $parameter,
            ArgumentAction::Service,
            $identifier,
            null,
        );
    }

    public static function tagged(string $parameter, string $tag): self
    {
        self::validateTarget($tag);

        return new self(
            $parameter,
            ArgumentAction::Tagged,
            $tag,
            null,
        );
    }

    public static function optionalService(
        string $parameter,
        string $identifier,
    ): self {
        self::validateTarget($identifier);

        return new self(
            $parameter,
            ArgumentAction::OptionalService,
            $identifier,
            null,
        );
    }

    public static function omitDefault(string $parameter): self
    {
        return new self(
            $parameter,
            ArgumentAction::OmitDefault,
            '',
            null,
        );
    }

    private static function validateTarget(string $target): void
    {
        if ($target === '') {
            throw new DefinitionException(
                'An argument instruction requires a non-empty target.',
            );
        }
    }
}
