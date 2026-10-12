<?php

declare(strict_types=1);

namespace Flint\Tests\Fixtures\Container\Autowiring;

final class VariadicConsumer
{
    /**
     * @var list<string>
     */
    public readonly array $values;

    public function __construct(string ...$values)
    {
        $this->values = array_values($values);
    }
}
