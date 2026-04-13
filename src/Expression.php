<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Axiom\Resolvers\Resolver;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

/**
 * A compiled, callable expression.
 *
 * Wraps a {@see Source} tree together with the resolver machinery and any
 * {@see Definitions} it depends on, exposing it as a function you invoke
 * with inputs:
 *
 * ```php
 * $area = new Expression($source, $resolver, new Definitions(['PI' => new StaticSource(3.14159)]));
 * $area(['radius' => 5]); // ~78.54
 * ```
 */
final readonly class Expression
{
    public function __construct(
        public Source $source,
        public Resolver $resolver,
        public Definitions $definitions = new Definitions(),
        public ?ResolutionInspector $inspector = null,
    ) {}

    /**
     * Returns the names of the free variables in the expression that are not
     * covered by the bound definitions — i.e. the parameters the caller is
     * expected to provide as bindings.
     *
     * @return list<string>
     */
    public function parameters(): array
    {
        $parameters = [];

        foreach (FreeVariables::of($this->source) as $variable) {
            if ($this->definitions->has($variable['name'], $variable['namespace'])) {
                continue;
            }

            $parameters[] = $variable['namespace'] !== null
                ? $variable['namespace'] . '.' . $variable['name']
                : $variable['name'];
        }

        return $parameters;
    }

    /**
     * Invoke the expression with the given bindings. Returns the resolved value
     * (or null for a `None` result). Throws if resolution returns an error.
     *
     * @param array<string, mixed> $bindings
     */
    public function __invoke(array $bindings = []): mixed
    {
        return $this->call($bindings)->unwrap()->unwrapOr(null);
    }

    /**
     * Invoke the expression, returning the raw `Result<Option<mixed>, Throwable>`.
     *
     * @param array<string, mixed> $bindings
     * @return Result<Option<mixed>, Throwable>
     */
    public function call(array $bindings = []): Result
    {
        $context = new Context(
            bindings: new Bindings($bindings),
            definitions: $this->definitions,
            inspector: $this->inspector,
        );

        return $this->resolver->resolve($this->source, $context);
    }

    public function withDefinitions(Definitions $definitions): self
    {
        return new self($this->source, $this->resolver, $definitions, $this->inspector);
    }

    public function withInspector(ResolutionInspector $inspector): self
    {
        return new self($this->source, $this->resolver, $this->definitions, $inspector);
    }
}
