<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Axiom\Exceptions\BoundaryViolation;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Operators\UnaryOverloader;
use Superscript\Axiom\Resolvers\BindableResolver;
use Superscript\Axiom\Resolvers\Resolver;
use Superscript\Axiom\Types\OptionType;
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
 * hosts) before evaluation, with violations aggregated and named. The
 * guarantee: declared inputs cannot deliver garbage past the boundary;
 * undeclared inputs cannot touch anything certified — they are inert, an
 * explicit Unknown, or a named error.
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

        // One dialect, both semantics: wire the operator stacks into the
        // resolver graph unless the host already bound its own (the legacy
        // configuration path — prefer contributing Extensions to the
        // Dialect, which the checker consumes too).
        if ($this->resolver instanceof BindableResolver) {
            if (!$this->resolver->has(OperatorOverloader::class)) {
                $this->resolver->instance(OperatorOverloader::class, $this->dialect->operators());
            }

            if (!$this->resolver->has(UnaryOverloader::class)) {
                $this->resolver->instance(UnaryOverloader::class, $this->dialect->unaryOperators());
            }
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
     * inputs as the environment's leaves. Fails when a declared∧defined
     * symbol disagrees (see TypeEnvironment::agreementMismatches).
     *
     * @return Result<Type, TypeMismatch>
     */
    public function infer(): Result
    {
        $environment = $this->environment();
        $inference = $this->inference();

        $disagreements = $environment->agreementMismatches($inference);

        if ($disagreements !== []) {
            return Err(new TypeMismatch('Declarations and definitions disagree.', $disagreements));
        }

        return $inference->infer($this->source, $environment);
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

        $context = new Context(
            bindings: $admitted->unwrap(),
            definitions: $this->definitions,
            inspector: $this->inspector,
        );

        return $this->resolver->resolve($this->source, $context);
    }

    /**
     * The boundary: every declared binding passes through its declared type
     * (coerce or assert, per policy) before evaluation; required declared
     * inputs must be present unless a definition can satisfy them; a binding
     * may shadow a definition only when declared — the declaration is the
     * typed license to shadow. Violations aggregate, named by binding.
     * Admitted values enter as explicit dotted keys, which win over descent,
     * so the typed value shadows the raw one at lookup.
     *
     * @param array<string, mixed> $raw
     * @return Result<Bindings, BoundaryViolation>
     */
    private function admit(array $raw): Result
    {
        $bindings = new Bindings($raw);
        $violations = [];
        $overlay = [];

        foreach ($bindings->keys() as $key) {
            if ($this->definitions->has($key) && !isset($this->declarations[$key])) {
                $violations[] = sprintf('binding [%s] shadows a definition; declare its type to permit this', $key);
            }
        }

        foreach ($this->declarations as $key => $type) {
            $namespace = null;
            $name = $key;

            if (str_contains($key, '.')) {
                [$namespace, $name] = explode('.', $key, 2);
            }

            if (!$bindings->has($name, $namespace)) {
                if (!$type instanceof OptionType && !$this->definitions->has($name, $namespace)) {
                    $violations[] = sprintf('required input [%s] is missing', $key);
                }

                continue;
            }

            $value = $bindings->get($name, $namespace)->unwrap();

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

        return Ok(new Bindings([...$raw, ...$overlay]));
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
