<?php declare(strict_types=1);
namespace Careminate\Console;

use Psr\Container\ContainerInterface;
use Careminate\Support\Console\Display;
use Careminate\Console\Contracts\CommandInterface;

class Kernel
{
    use Display;

    public function __construct(
        private ContainerInterface $container,
        private Application $application
    ) {}

    public function handle(): int
    { 
        try {
            // Add command handling logic here
            $this->green("Console application started successfully!");
             // Register commands with the container
             $this->registerCommands();

             // Run the console application, returning a status code
             $status = $this->application->run();

            // return the status code
            return EXIT_SUCCESS;
        } catch (\Throwable $e) {
            throw new ConsoleException(
                $e->getMessage(),
                $e->getCode() ?: EXIT_ERROR
            );
        }
    }

    private function registerCommands(): void
{
    $commandFiles = new \DirectoryIterator(__DIR__ . '/Commands');
    $namespace = $this->container->get('base-commands-namespace');

    foreach ($commandFiles as $commandFile) {
        // Skip directories and non-PHP files
        if ($commandFile->isDir() || $commandFile->getExtension() !== 'php') {
            continue;
        }

        // Get the class name from the filename
        $className = $commandFile->getBasename('.php');
        $commandNameSpace = $namespace . $className;
// dd($className);
// dd($commandNameSpace);
        // Ensure the class exists and implements CommandInterface
        if (!class_exists($commandNameSpace) || !is_subclass_of($commandNameSpace, CommandInterface::class)) {
            continue;
        }

        // Register the command with the container
        $commandName = (new \ReflectionClass($commandNameSpace))->getProperty('name')->getDefaultValue();
        $this->container->add($commandName, $commandNameSpace);
// dd($commandName);
    }
}

   
}
