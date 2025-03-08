<?php 
namespace Careminate\Views\Components;

use Careminate\Logs\Log;

class Component
{
    public function render(): string
    {
        ob_start(); // Start output buffering
        echo $this->renderHtml();
        return ob_get_clean(); // Get the buffered output and clean it
    }

    protected function renderHtml(): string
    {
        // This method must be implemented in derived classes
        throw new Log("Method renderHtml() must be implemented in the subclass.");
    }
}