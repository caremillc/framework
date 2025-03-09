<?php 
namespace Careminate\Http\Responses;

class Response 
{
    /**
     * Sends a response with the given content and status code.
     *
     * @param string $content    The content to send in the response.
     * @param int    $statusCode The HTTP status code (default: 200).
     */
    public static function send(string $content, int $statusCode = 200): void
    {
        // Send the HTTP status code and content, then terminate the script.
        http_response_code($statusCode);
        echo $content;
        exit;
    }

    /**
     * Sets the HTTP status code for the response.
     *
     * @param int $code The HTTP status code.
     */
    public function setStatusCode(int $code): void
    {
        // Set the HTTP status code
        http_response_code($code);
    }

    /**
     * Redirects the user back to the previous page.
     *
     * @return $this
     */
    public function back(): self
    {
        // Ensure headers haven't been sent before redirecting
        if (!headers_sent()) {
            header('Location: ' . $_SERVER['HTTP_REFERER']);
            exit; // Exit to stop further processing
        }

        // Handle case where headers were already sent (you can throw an exception or log)
        // Throw an exception or return an error message if required
        throw new \RuntimeException('Headers already sent, cannot redirect.');
    }


}