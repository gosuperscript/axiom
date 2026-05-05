<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Closure;
use InvalidArgumentException;
use Superscript\Axiom\Resolvers\Resolver;
use Superscript\Axiom\Resolvers\ResolverPreset;
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
        public SchemaVersion $version = SchemaVersion::V1,
    ) {}

    /**
     * Build an {@see Expression} from a versioned {@see Schema} envelope.
     *
     * The resolver stack is derived from the schema's {@see SchemaVersion}
     * via {@see ResolverPreset}; consumers customize via the optional
     * `$customize` closure, which receives a version-locked preset and
     * must return a {@see ResolverPreset} for the same version.
     *
     * @param (Closure(ResolverPreset): ResolverPreset)|null $customize
     */
    public static function fromSchema(
        Schema $schema,
        ?Closure $customize = null,
        Definitions $definitions = new Definitions(),
        ?ResolutionInspector $inspector = null,
    ): self {
        $preset = ResolverPreset::for($schema->version);

        if ($customize !== null) {
            $customized = $customize($preset);

            if (! $customized instanceof ResolverPreset) {
                throw new InvalidArgumentException(
                    'The customize closure must return a ResolverPreset.',
                );
            }

            $preset = $customized;
        }

        return new self(
            source: $schema->source,
            resolver: $preset->build(),
            definitions: $definitions,
            inspector: $inspector,
            version: $schema->version,
        );
    }

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
            bindings: new Bindings($bindings),
            definitions: $this->definitions,
            inspector: $this->inspector,
        );

        return $this->resolver->resolve($this->source, $context);
    }

    public function withDefinitions(Definitions $definitions): self
    {
        return new self($this->source, $this->resolver, $definitions, $this->inspector, $this->version);
    }

    public function withInspector(ResolutionInspector $inspector): self
    {
        return new self($this->source, $this->resolver, $this->definitions, $inspector, $this->version);
    }
}
