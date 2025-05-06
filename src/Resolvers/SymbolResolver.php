<?php

namespace Superscript\Abacus\Resolvers;

use Superscript\Abacus\Source;
use Superscript\Abacus\Sources\SymbolSource;
use Superscript\Abacus\SymbolRegistry;
use Superscript\Monads\Result\Result;

final readonly class SymbolResolver implements Resolver
{
    public function __construct(
        public Resolver $resolver,
        public SymbolRegistry $symbolRegistry,
    ) {
    }

    /**
     * @param SymbolSource $source
     */
    public function resolve(Source $source): Result
    {
        return $this->symbolRegistry->get($source->name)
            ->andThen(fn(Source $source) => $this->resolver->resolve($source)->transpose())->transpose();
    }

    public static function supports(Source $source): bool
    {
        return $source instanceof SymbolSource;
    }
}