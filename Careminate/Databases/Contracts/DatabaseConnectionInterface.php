<?php 
namespace Careminate\Databases\Contracts;

interface DatabaseConnectionInterface
{
    public function getPDO(): \PDO;
}