<?php

declare(strict_types=1);

namespace Superscript\Schema\Sources;

use Closure;
use Superscript\Schema\Resolvers\Resolver;
use Superscript\Schema\Source;
use Superscript\Schema\SymbolRegistry;

final readonly class SymbolSource implements Source
{
    public function __construct(
        public string $name,
        public ?string $namespace = null,
    ) {}

    public function resolver(): Closure
    {
        return fn(SymbolRegistry $registry, Resolver $resolver) => $registry->get($this->name, $this->namespace)
            ->andThen(fn(Source $source) => $resolver->resolve($source)->transpose())->transpose();
    }
}
