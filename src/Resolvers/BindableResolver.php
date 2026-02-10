<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

interface BindableResolver extends Resolver
{
    /** @param class-string $key */
    public function instance(string $key, mixed $concrete): void;
}
