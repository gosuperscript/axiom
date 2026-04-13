<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Axiom\Exceptions\TypeCheckException;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Resolvers\BindableResolver;
use Superscript\Axiom\Resolvers\Resolver;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

/**
 * A compiled, callable expression.
 *
 * Wraps a {@see Source} tree together with the resolver machinery, any
 * {@see Definitions} it depends on, and a declared parameter schema,
 * exposing it as a function you invoke with inputs:
 *
 * ```php
 * $area = new Expression(
 *     $source, $resolver,
 *     definitions: new Definitions(['PI' => new StaticSource(3.14159)]),
 *     parameters: ['radius' => new NumberType()],
 * );
 * $area->validate();      // Result<Type, TypeCheckException>
 * $area(['radius' => 5]); // ~78.54
 * ```
 */
final readonly class Expression
{
    /**
     * @param array<string, Type|array<string, Type>> $parameters
     *     Declared types of free variables. Flat map of `name => Type`
     *     or nested `namespace => [name => Type]`.
     */
    public function __construct(
        public Source $source,
        public Resolver $resolver,
        public Definitions $definitions = new Definitions(),
        public ?ResolutionInspector $inspector = null,
        public array $parameters = [],
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

        foreach (UnboundSymbols::in($this->source) as $symbol) {
            if ($this->definitions->has($symbol->name, $symbol->namespace)) {
                continue;
            }

            $parameters[] = $symbol->namespace !== null
                ? $symbol->namespace . '.' . $symbol->name
                : $symbol->name;
        }

        return $parameters;
    }

    /**
     * Statically type-check the expression. Returns the result {@see Type}
     * when the body can be fully typed given the declared parameter schema
     * and definitions; otherwise an error pinpointing the failure.
     *
     * @return Result<Type, TypeCheckException>
     */
    public function validate(): Result
    {
        $context = new Context(
            bindings: new Bindings([], $this->parameters),
            definitions: $this->definitions,
            operators: $this->operatorsFromResolver(),
        );

        return TypeChecker::check($this->source, $context);
    }

    /**
     * Invoke the expression with the given bindings.
     *
     * @param array<string, mixed> $bindings
     * @return Result<Option<mixed>, Throwable>
     */
    public function __invoke(array $bindings = []): Result
    {
        return $this->call($bindings);
    }

    /**
     * Invoke the expression with the given bindings.
     *
     * @param array<string, mixed> $bindings
     * @return Result<Option<mixed>, Throwable>
     */
    public function call(array $bindings = []): Result
    {
        $context = new Context(
            bindings: new Bindings($bindings, $this->parameters),
            definitions: $this->definitions,
            inspector: $this->inspector,
            operators: $this->operatorsFromResolver(),
        );

        return $this->resolver->resolve($this->source, $context);
    }

    public function withDefinitions(Definitions $definitions): self
    {
        return new self($this->source, $this->resolver, $definitions, $this->inspector, $this->parameters);
    }

    public function withInspector(ResolutionInspector $inspector): self
    {
        return new self($this->source, $this->resolver, $this->definitions, $inspector, $this->parameters);
    }

    /**
     * @param array<string, Type|array<string, Type>> $parameters
     */
    public function withParameters(array $parameters): self
    {
        return new self($this->source, $this->resolver, $this->definitions, $this->inspector, $parameters);
    }

    private function operatorsFromResolver(): ?OperatorOverloader
    {
        if ($this->resolver instanceof BindableResolver && $this->resolver->has(OperatorOverloader::class)) {
            $operators = $this->resolver->get(OperatorOverloader::class);

            return $operators instanceof OperatorOverloader ? $operators : null;
        }

        return null;
    }
}
