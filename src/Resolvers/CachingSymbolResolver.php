<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use Superscript\Axiom\ResolutionInspector;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\SymbolRegistry;
use Superscript\Monads\Result\Result;

final class CachingSymbolResolver implements Resolver
{
    /** @var array<string, Result<\Superscript\Monads\Option\Option<mixed>, \Throwable>> */
    private array $cache = [];

    private SymbolResolver $symbolResolver;

    public function __construct(
        Resolver $resolver,
        SymbolRegistry $symbolRegistry,
        private ?ResolutionInspector $inspector = null,
    ) {
        $this->symbolResolver = new SymbolResolver($resolver, $symbolRegistry, $inspector);
    }

    /**
     * @param SymbolSource $source
     */
    public function resolve(Source $source): Result
    {
        $key = $source->namespace !== null
            ? "{$source->namespace}.{$source->name}"
            : $source->name;

        if (array_key_exists($key, $this->cache)) {
            $this->inspector?->annotate('label', $key);
            $this->inspector?->annotate('cache', 'hit');

            return $this->cache[$key];
        }

        $this->inspector?->annotate('cache', 'miss');

        $result = $this->symbolResolver->resolve($source);
        $this->cache[$key] = $result;

        return $result;
    }
}
