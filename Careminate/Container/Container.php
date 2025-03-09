<?php 
namespace Careminate\Container;

class Container
{
    protected $bindings = [];

    public function bind(string $abstract, \Closure $concrete)
    {
        $this->bindings[$abstract] = $concrete;
    }

    public function make(string $abstract)
    {
        if (!isset($this->bindings[$abstract])) {
            throw new \Exception("No binding found for {$abstract}");
        }

        return $this->bindings[$abstract]();
    }
}
