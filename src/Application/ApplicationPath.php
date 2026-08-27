<?php

declare(strict_types=1);

namespace Careminate\Application;

enum ApplicationPath: string
{
    case App = 'app';
    case Bootstrap = 'bootstrap';
    case Config = 'config';
    case Public = 'public';
    case Resources = 'resources';
    case Routes = 'routes';
    case Storage = 'storage';
    case Cache = 'cache';
}