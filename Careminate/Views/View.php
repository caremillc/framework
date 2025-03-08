<?php
namespace Careminate\Views;

class View
{
    public static function make($view, ?array $data = [])
    {
        $view = str_replace('.', '/', $view);
        $path = config('view.path');
        extract($data);
        include $path.'/'.$view.'.tpl.php';
    }

    /**
     * A static helper function to make views simpler to load
     *
     * @param string $view The view name
     * @param array|null $data Optional data to pass to the view
     * @return void
     */
    public static function render($view, ?array $data = [])
    {
        return self::make($view, $data);
    }
}