<?php 
namespace Careminate\Console\Contracts;

interface CommandInterface
{ 
    public function name(): string;

    public function description(): string;

    /** 
     * Executes the command with the provided parameters
     *
     * @param array $params The parameters to execute the command
     * @return int The exit status of the command execution
     */
    //php caremi database:migrations:rollback --down=2
    public function execute(array $params = []): int;
}
