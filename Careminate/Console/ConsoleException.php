<?php 
namespace Careminate\Console;

class ConsoleException extends \RuntimeException
{
    public function __construct(
        string $message = "",
        private int $exitCode = 1
    ) {
        parent::__construct($message);
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }
}
 
