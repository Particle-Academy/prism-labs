<?php

declare(strict_types=1);

namespace App\Lab;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use SensitiveParameter;

final class LabConnectionFactory extends ConnectionFactory
{
    public function __construct(Container $container, private readonly Closure $validate)
    {
        parent::__construct($container);
    }

    public function make(#[SensitiveParameter] array $config, $name = null)
    {
        // ::read/::direct may resolve PDO before ConnectionEstablished fires.
        // Validate every endpoint before the native factory creates any PDO.
        ($this->validate)($config);

        return parent::make($config, $name);
    }
}
