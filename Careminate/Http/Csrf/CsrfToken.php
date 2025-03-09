<?php 
namespace Careminate\Http\Csrf;

use Careminate\Sessions\Session;
use Careminate\Http\Requests\Request;
use RuntimeException;

class CsrfToken
{
    private Request $request;

    // Adjust constructor to allow optional Request argument
    public function __construct(?Request $request = null)
    {
        $this->request = $request ?? new Request();
    }

    // Validate the CSRF token
    public function validateToken(): void
    {
        $methods = ['POST', 'PUT', 'PATCH', 'DELETE'];

        if (in_array($this->request->getMethod(), $methods)) {
            $token = $this->request->post('_token');
            $sessionToken = Session::get('csrf_token');

            if (empty($token) || $token !== $sessionToken) {
                throw new RuntimeException('Invalid CSRF token');
            }
        }
    }

    // Generate a new CSRF token
    public static function generateCsrfToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    // Create CSRF token if not already present in the session
    public static function createCsrf(): void
    {
        if (!Session::has('csrf_token')) {
            $csrf = self::generateCsrfToken();
            Session::make('csrf_token', $csrf);
        }
    }
}
