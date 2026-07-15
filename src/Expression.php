<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use InvalidArgumentException;
use Superscript\Axiom\Exceptions\BoundaryViolation;
use Superscript\Axiom\Resolvers\Resolver;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeEnvironment;
use Superscript\Axiom\Types\TypeInference;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * A compiled, callable expression that can also type itself.
 *
 * Wraps a {@see Source} tree together with the resolver machinery, the
 * {@see Dialect} (operator rules + literal registry — one instance,
 * consumed by both the evaluator and the checker, so they cannot be
 * miscomposed), any {@see Definitions} it depends on, and the declared
 * input types:
 *
 * ```php
 * $area = new Expression($source, $resolver,
 *     definitions: new Definitions(['PI' => new StaticSource(3.14159)]),
 *     declarations: ['radius' => new NumberType()],
 * );
 * $area->check(new NumberType()); // static: certified
 * $area(['radius' => '5']);       // boundary coerces '5' → 5, then ~78.54
 * ```
 *
 * Certification is conditional — "if inputs inhabit their declared types" —
 * and the boundary establishes the condition: declared bindings pass
 * through their declared types (coerce by default, assert for strict
 * hosts) before evaluation, with violations aggregated and named; every
 * undeclared binding key is stripped before evaluation begins. The
 * declaration list is the expression's complete public signature —
 * declarations and definitions are disjoint namespaces (a symbol is a
 * parameter or a derived value, never both; enforced at construction), so
 * shadowing a definition is unrepresentable. The guarantee: declared
 * inputs cannot deliver garbage past the boundary; undeclared inputs
 * cannot touch anything at all — they are stripped, an explicit Unknown,
 * or a named error.
 */
final readonly class Expression
{
    public Dialect $dialect;

    /**
     * @param array<string, Type> $declarations
     */
    public function __construct(
        public Source $source,
        public Resolver $resolver,
        public Definitions $definitions = new Definitions(),
        public ?ResolutionInspector $inspector = null,
        ?Dialect $dialect = null,
        public array $declarations = [],
        public Boundary $boundary = Boundary::Coerce,
    ) {
        $this->dialect = $dialect ?? Dialect::core();

        // Symbol lookup is exact-key only (no descent), so declared and
        // defined names can only collide literally: Symbol('turnover',
        // ns: 'customer') and member access on a declared customer record
        // are distinct, unambiguous programs.
        $collisions = array_filter(
            array_keys($this->declarations),
            fn(string $key) => $this->definitions->has($key),
        );

        if ($collisions !== []) {
            throw new InvalidArgumentException(sprintf(
                'Declarations and definitions are disjoint namespaces, but [%s] %s both declared and defined. A symbol is a parameter or a derived value, never both; model an override as an Option-typed parameter the definition consults.',
                implode('], [', $collisions),
                count($collisions) === 1 ? 'is' : 'are',
            ));
        }
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
     * What does this expression return? Inferred through the dialect's own
     * stacks over the same Definitions the evaluator walks, with declared
     * inputs as the environment's leaves. The definition graph must be
     * well-founded first — a cyclic definition would recurse without
     * terminating at runtime, and that is a graph property declarations can
     * never repair (see DefinitionGraph).
     *
     * @return Result<Type, TypeMismatch>
     */
    public function infer(): Result
    {
        $cycles = DefinitionGraph::cycles($this->definitions);

        if ($cycles !== []) {
            return Err(new TypeMismatch(
                'The definition graph is not well-founded; evaluation would recurse without terminating.',
                $cycles,
            ));
        }

        return $this->inference()->infer($this->source, $this->environment());
    }

    /**
     * Does this expression produce the expected type? infer + assignability.
     *
     * @return Result<Type, TypeMismatch>
     */
    public function check(Type $expected): Result
    {
        return $this->infer()->andThen(
            fn(Type $actual) => TypeRelations::isTypeAssignableTo($actual, $expected)->map(fn() => $actual),
        );
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
     * Invoke the expression with the given bindings. Declared bindings pass
     * the boundary first.
     *
     * @param array<string, mixed> $bindings
     * @return Result<Option<mixed>, Throwable>
     */
    public function call(array $bindings = []): Result
    {
        $admitted = $this->admit($bindings);

        if ($admitted->isErr()) {
            return Err($admitted->unwrapErr());
        }

        // The dialect rides the context, exactly as bindings do: the same
        // instance infer()/check() read is the one this evaluation runs.
        // Resolvers hold no operator state, so miscomposition is
        // unrepresentable — not guarded against.
        $context = new Context(
            bindings: $admitted->unwrap(),
            definitions: $this->definitions,
            inspector: $this->inspector,
            dialect: $this->dialect,
        );

        return $this->resolver->resolve($this->source, $context);
    }

    /**
     * The boundary: every declared binding passes through its declared type
     * (coerce or assert, per policy) before evaluation, and every
     * undeclared key is stripped — the declaration list is the expression's
     * complete public signature, and disjointness (enforced at
     * construction) means no admitted binding can name a definition.
     * Violations aggregate, named by binding. Callers bind keys exactly as
     * declared — symbol lookup has no other reading.
     *
     * @param array<string, mixed> $raw
     * @return Result<Bindings, BoundaryViolation>
     */
    private function admit(array $raw): Result
    {
        $violations = [];
        $overlay = [];

        foreach ($this->declarations as $key => $type) {
            if (!array_key_exists($key, $raw)) {
                // Required-ness is a property of the projection, not the
                // concrete class: Union(Option<Number>, String) has shape
                // (Number | String)? and a missing binding is legal absence.
                if (!($type->shape() instanceof OptionShape)) {
                    $violations[] = sprintf('required input [%s] is missing', $key);
                }

                continue;
            }

            $value = $raw[$key];

            $admitted = match ($this->boundary) {
                Boundary::Coerce => $type->coerce($value),
                Boundary::Assert => $type->assert($value),
            };

            if ($admitted->isErr()) {
                $violations[] = sprintf('binding [%s]: %s', $key, $admitted->unwrapErr()->getMessage());

                continue;
            }

            // An absence reading ('' → None). OptionType never produces one —
            // it wraps absence as a present null — so this is always a
            // required input that dissolved at the boundary.
            if ($admitted->unwrap()->isNone()) {
                $violations[] = sprintf('binding [%s] reads as missing, but %s is required', $key, TypeDescriber::describe($type));

                continue;
            }

            $overlay[$key] = $admitted->unwrap()->unwrapOr(null);
        }

        if ($violations !== []) {
            return Err(new BoundaryViolation($violations));
        }

        // The declaration list is the expression's complete public
        // signature: only the admitted, declared slice enters evaluation.
        // Stripping is what makes undeclared inputs inert — they can never
        // feed an undeclared symbol — while superset contexts stay legal
        // to pass.
        return Ok(new Bindings($overlay));
    }

    private function environment(): TypeEnvironment
    {
        return new TypeEnvironment($this->definitions, $this->declarations);
    }

    private function inference(): TypeInference
    {
        return new TypeInference(
            $this->dialect->operators(),
            $this->dialect->unaryOperators(),
            $this->dialect->literals(),
        );
    }

    public function withDefinitions(Definitions $definitions): self
    {
        return new self($this->source, $this->resolver, $definitions, $this->inspector, $this->dialect, $this->declarations, $this->boundary);
    }

    public function withInspector(ResolutionInspector $inspector): self
    {
        return new self($this->source, $this->resolver, $this->definitions, $inspector, $this->dialect, $this->declarations, $this->boundary);
    }
}
