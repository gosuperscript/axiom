<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use Superscript\Axiom\ResolutionInspector;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\SymbolRegistry;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

final class SymbolResolver implements Resolver
{
    /** @var array<string, Result<Option<mixed>, \Throwable>> */
    private array $memo = [];

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
        $key = $source->namespace !== null
            ? "{$source->namespace}.{$source->name}"
            : $source->name;

        if (array_key_exists($key, $this->memo)) {
            $this->inspector?->annotate('label', $key);
            $this->inspector?->annotate('memo', 'hit');

            return $this->memo[$key];
        }

        $this->inspector?->annotate('label', $key);
        $this->inspector?->annotate('memo', 'miss');

        $result = $this->symbolRegistry->get($source->name, $source->namespace)
            ->andThen(fn(Source $source) => $this->resolver->resolve($source)->transpose())
            ->transpose()
            ->inspect(fn(Option $option) => $option->inspect(fn(mixed $value) => $this->inspector?->annotate('result', $value)));

        $this->memo[$key] = $result;

        return $result;
    }
}
