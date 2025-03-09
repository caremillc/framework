<?php 
namespace Careminate\Http\Controllers;

use Careminate\Http\Validations\Validate;

abstract class AbstractController
{
    public function validate(array|object $requests, array $rules, array|null $attributes = []){
        return Validate::make($requests, $rules, $attributes);
    } 
}