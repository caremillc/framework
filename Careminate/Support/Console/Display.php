<?php declare(strict_types=1);
namespace Careminate\Support\Console;

trait Display
{
    private array $colors = [
        'green'  => "\e[32m",
        'yellow' => "\e[33m",
        'red'    => "\e[91m"
    ];

    private const RESET = "\e[0m";

    public function green(string $text): void
    {
        $this->output($this->colors['green'], $text);
    }

    public function yellow(string $text): void
    {
        $this->output($this->colors['yellow'], $text);
    }

    public function red(string $text): void
    {
        $this->output($this->colors['red'], $text);
    }

    private function output(string $color, string $text): void
    {
        echo $color . $text . self::RESET . PHP_EOL;
    }
}

