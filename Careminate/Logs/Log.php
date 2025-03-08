<?php 
namespace Careminate\Logs;

use App\Views\Components\ExceptionComponent;

class Log extends \Exception
{
    protected string $logFile;
    protected bool $displayErrors;
    protected bool $logToFile;

    public function __construct(string $message, int $code = 0, ?\Exception $previous = null)
    {
        // Load configuration settings
        $config = include base_path('config/log.php');
// var_dump($config['log_to_file']);
        $this->logFile = $config['log_file'] ?? 'error.log';
        $this->displayErrors = $config['display_errors'];
        $this->logToFile = $config['log_to_file'];

        parent::__construct($message, $code, $previous);

        if ($this->logToFile) {
            $this->logError();
        }

        if ($this->displayErrors) {
            $this->displayError();
        }
    }

    public function logError(): void
    {
        $logMessage = date('Y-m-d H:i:s') . " - Error: {$this->getMessage()} in {$this->getFile()} on line {$this->getLine()}\n";
        file_put_contents(storage_path('logs/' . $this->logFile), $logMessage, FILE_APPEND);
    }

    public function displayError(): void
    {
        $errorDetails = [
            'message' => $this->getMessage(),
            'line' => $this->getLine(),
            'file' => $this->getFile(),
            'trace' => $this->getTraceAsString(),
        ];

        $errorComponent = new ExceptionComponent($errorDetails);
        echo $errorComponent->render();
        exit; // Stop further execution
    }
}
