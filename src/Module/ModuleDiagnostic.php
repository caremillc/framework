<?php

declare(strict_types=1);

namespace Careminate\Module;

final readonly class ModuleDiagnostic
{
    public function __construct(
        public string $name,
        public string $class,
        public ModuleStatus $status,
        public string $message,
        public ?int $bootPosition = null,
    ) {
    }

    /**
     * @return array{
     *     name: string,
     *     class: string,
     *     status: string,
     *     message: string,
     *     boot_position: int|null
     * }
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'class' => $this->class,
            'status' => $this->status->value,
            'message' => $this->message,
            'boot_position' => $this->bootPosition,
        ];
    }
}
