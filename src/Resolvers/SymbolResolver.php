<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\SymbolRegistry;
use Superscript\Monads\Result\Result;

final readonly class SymbolResolver implements Resolver
{
    public function __construct(
        public Resolver $resolver,
        public SymbolRegistry $symbolRegistry,
    ) {}

    /**
     * @param SymbolSource $source
     */
    public function resolve(Source $source): Result
    {
        return $this->symbolRegistry->get($source->name, $source->namespace)
            ->andThen(fn(Source $source) => $this->resolver->resolve($source)->transpose())->transpose();
    }
}
