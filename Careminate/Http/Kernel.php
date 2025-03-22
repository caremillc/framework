<?php
namespace Careminate\Http;

use Doctrine\DBAL\Connection;
use Careminate\Http\Requests\Request;
use Careminate\Routing\HttpException;
use Psr\Container\ContainerInterface;
use Careminate\Http\Responses\Response;
use Careminate\Routing\RouterInterface;

class Kernel
{
    private string $appEnv;
    private string $appKey;
    private string $appVersion;

    public function __construct(
        private RouterInterface $router,
        private ContainerInterface $container
    ){
        $this->appEnv = $this->container->get('APP_ENV');
        $this->appKey = $this->container->get('APP_KEY');
        $this->appVersion = $this->container->get('APP_VERSION');

        // Validate essential configurations
        if (!file_exists('.env') || empty($this->appKey)) {
            throw new \RuntimeException('APP_KEY is empty/missing or .env file is not found.');
        }

        if (!file_exists('.env') || empty($this->appEnv)) {
            throw new \RuntimeException('APP_ENV is empty/missing or .env file is not found.');
        }
        if (!file_exists('.env') || empty($this->appVersion)) {
            throw new \RuntimeException('APP_VERSION is empty/missing or .env file is not found.');
        }
    }
    
    public function handle(Request $request): Response
    {
        try {

            // dd($this->container->get(Connection::class));
            [$routeHandler, $vars] = $this->router->dispatch($request, $this->container);
       
            $response = call_user_func_array($routeHandler, $vars);

        } catch (\Exception $exception) {
            $response = $this->createExceptionResponse($exception);
        }
         // catch (\Exception $exception) {
        //     $response = new Response($exception->getMessage(), 500);
        // }

        return $response;
    }

    private function createExceptionResponse(\Exception $exception): Response
	{
		// Check if the environment is development or local testing
		if (in_array($this->appEnv, ['dev', 'local', 'test'])) {
			// In development or local testing, rethrow the exception for detailed debugging
			throw $exception;
		}

		// Production environment handling
		if ($exception instanceof HttpException) {
			// Return a response with the HTTP status and message for HTTP exceptions
			return new Response($exception->getMessage(), $exception->getStatusCode());
		}

		// For all other exceptions, return a generic server error message
		return new Response('Server error, CHECK app_env', Response::HTTP_INTERNAL_SERVER_ERROR);
	}

}
