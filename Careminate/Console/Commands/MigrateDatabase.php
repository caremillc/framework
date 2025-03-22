<?php 
namespace Careminate\Console\Commands;

use Careminate\Support\Console\Display;
use Careminate\Console\Contracts\CommandInterface;

class MigrateDatabase implements CommandInterface
{
    use Display;
    // Command name
    private string $name = 'database:migrations:migrate';

    // Command description
    private string $description = 'Migrate the database by applying all unapplied migrations.';

    /**
     * Get the name of the command
     *
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Get the description of the command
     *
     * @return string
     */
    public function description(): string
    {
        return $this->description;
    }


    public function execute(array $params = []): int
    {
        $this->green("Executing MigrateDatabase command..."). PHP_EOL;

        return EXIT_SUCCESS;
    }
}

