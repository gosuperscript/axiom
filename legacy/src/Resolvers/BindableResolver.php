<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use Psr\Container\ContainerInterface;

interface BindableResolver extends Resolver, ContainerInterface
{
    /** @param class-string $key */
    public function instance(string $key, mixed $concrete): void;
}
