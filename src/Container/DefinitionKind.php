<?php

declare(strict_types=1);

namespace Careminate\Container;

enum DefinitionKind: string
{
    case ClassName = 'class';
    case CallableFactory = 'callable_factory';
    case FactoryService = 'factory_service';
    case FactoryObject = 'factory_object';
    case Value = 'value';
}
