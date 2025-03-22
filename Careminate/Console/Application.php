<?php 
namespace Careminate\Console;

use Psr\Container\ContainerInterface;

class Application
{
    public function __construct(private ContainerInterface $container){}

    public function run(): int
    {
        // Use environment variables to obtain the command name
        $argv = $_SERVER['argv'];
        $commandName = $argv[1] ?? null;

        // Throw an exception if no command name is provided
        if (!$commandName) {
            throw new ConsoleException('A command name must be provided');
        }

        // Use command name to obtain a command object from the container
        $command = $this->container->get($commandName);

        // Parse variables to obtain options and args
        $args = array_slice($argv, 2);
        // Debugging the args
        var_dump($args); // Use var_dump or print_r if you're not using Laravel's dd()

        $options = $this->parseOptions($args);

        // Execute the command, returning the status code
        $status = $command->execute($options);

        // Return the status code
        return $status;
    }

    private function parseOptions(array $args): array
    {
        $options = [];

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                // This is an option
                $option = explode('=', substr($arg, 2), 2); // Limiting explode to avoid excessive splits
                $options[$option[0]] = $option[1] ?? true;
            }
        }

        return $options;
    }
}
