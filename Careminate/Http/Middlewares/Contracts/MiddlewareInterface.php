<?php 
namespace Careminate\Http\Middlewares\Contracts;

interface MiddlewareInterface
{
    /**
     * @param mixed $request
     * @param mixed $next
     * @param mixed ...$role
     *
     * @return mixed
     */
    public function handle($request, $next,...$role);
}