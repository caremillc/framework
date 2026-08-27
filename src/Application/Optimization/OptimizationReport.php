<?php

declare(strict_types=1);

namespace Careminate\Application\Optimization;

final readonly class OptimizationReport
{
    public function __construct(
        public string $cachePath,
        public float $durationMilliseconds,
        public int $memoryDeltaBytes,
        public int $cacheSizeBytes,
    ) {
    }

    /**
     * @return array{
     *     cache_path: string,
     *     duration_ms: float,
     *     memory_delta_bytes: int,
     *     cache_size_bytes: int
     * }
     */
    public function toArray(): array
    {
        return [
            'cache_path' => $this->cachePath,
            'duration_ms' => $this->durationMilliseconds,
            'memory_delta_bytes' => $this->memoryDeltaBytes,
            'cache_size_bytes' => $this->cacheSizeBytes,
        ];
    }
}
