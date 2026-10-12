<?php

declare(strict_types=1);

namespace Flint\Tests\Fixtures\Http;

use Flint\Http\Output\ResponseOutput;
use RuntimeException;

final class RecordingResponseOutput implements ResponseOutput
{
    /**
     * @var list<list<string|int|bool>>
     */
    public array $events = [];

    public function __construct(
        private readonly ?RuntimeException $beginFailure = null,
    ) {
    }

    public function begin(): void
    {
        if ($this->beginFailure !== null) {
            throw $this->beginFailure;
        }

        $this->events[] = ['begin'];
    }

    public function writeHeader(
        string $name,
        string $value,
        bool $replace,
    ): void {
        $this->events[] = ['header', $name, $value, $replace];
    }

    public function writeStatus(int $status): void
    {
        $this->events[] = ['status', $status];
    }

    public function writeBody(string $body): void
    {
        $this->events[] = ['body', $body];
    }
}
