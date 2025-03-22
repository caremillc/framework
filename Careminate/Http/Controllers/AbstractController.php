<?php 
namespace Careminate\Http\Controllers;

use Psr\Container\ContainerInterface;
use Careminate\Http\Responses\Response;

/**
 * AbstractController serves as a base class for controllers, providing methods
 * for rendering templates and managing dependency injection via a container.
 */
abstract class AbstractController
{
    /**
     * @var ContainerInterface|null
     * The container instance used for accessing shared services (e.g., Twig, database, etc.).
     */
    protected ?ContainerInterface $container = null;

    /**
     * Set the container instance for the controller.
     * 
     * This method allows other parts of the application to inject the container
     * into the controller, enabling access to services and dependencies.
     *
     * @param ContainerInterface $container The container instance to be set.
     * 
     * @return void
     */
    public function setContainer(ContainerInterface $container): void
    {
        // Store the container instance for use within the controller.
        $this->container = $container;
    }

    /**
     * Render a template and return the response.
     * 
     * This method uses Twig (or any other templating engine configured in the container)
     * to render the specified template with the provided parameters. It returns a Response
     * object containing the rendered content. If no response is passed, a new Response is created.
     *
     * @param string $template The template name to render.
     * @param array $parameters Parameters to pass to the template.
     * @param Response|null $response Optional response object to modify. If none is provided, a new one will be created.
     * 
     * @return Response The response object containing the rendered content.
     */
    public function render(string $template, array $parameters = [], ?Response $response = null): Response
    {
        // Render the template using the Twig service from the container.
        $content = $this->container->get('twig')->render($template, $parameters);

        // If no response object is passed, create a new one.
        $response ??= new Response();

        // Set the rendered content as the response body.
        $response->setContent($content);

        // Return the response object with the rendered content.
        return $response;
    }
}
