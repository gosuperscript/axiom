<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use Superscript\Axiom\ResolutionInspector;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\SymbolRegistry;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

final readonly class SymbolResolver implements Resolver
{
    public function __construct(
        public Resolver $resolver,
        public SymbolRegistry $symbolRegistry,
        private ?ResolutionInspector $inspector = null,
    ) {}

    /**
     * @param SymbolSource $source
     */
    public function resolve(Source $source): Result
    {
        $this->inspector?->annotate('label', $source->namespace !== null
            ? "{$source->namespace}.{$source->name}"
            : $source->name);

        return $this->symbolRegistry->get($source->name, $source->namespace)
            ->andThen(fn(Source $source) => $this->resolver->resolve($source)->transpose())
            ->transpose()
            ->inspect(fn(Option $option) => $option->inspect(fn(mixed $value) => $this->inspector?->annotate('result', $value)));
    }
}
