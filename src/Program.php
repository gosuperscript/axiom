<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Axiom\Exceptions\BoundaryViolation;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * A compiled, certified, callable program — the only callable thing in the
 * library. `Expression::compile()` is the sole constructor path a host
 * needs: every operator and symbol is already resolved, the return type is
 * a property, and running an unchecked program is unrepresentable because
 * nothing else can be run.
 *
 * ```php
 * $program = $expression->compile()->unwrap();
 * $program->returns;            // Type
 * $program(['radius' => '5']);  // boundary coerces, then evaluates — no dispatch
 * ```
 *
 * Certification is conditional — "if inputs inhabit their declared types" —
 * and compile() cannot prove future inputs, so the boundary is the one
 * runtime type check that survives compilation, by design: every declared
 * binding passes through its declared type (coerce by default, assert for
 * strict hosts) before evaluation, with violations aggregated and named;
 * every undeclared binding key is stripped. Declared inputs cannot deliver
 * garbage past the boundary; undeclared inputs cannot touch anything at all.
 */
final readonly class Program
{
    public Type $returns;

    /**
     * @param array<string, Type> $declarations
     */
    public function __construct(
        private CompiledNode $node,
        private array $declarations = [],
        private Boundary $boundary = Boundary::Coerce,
        private ?ResolutionInspector $inspector = null,
    ) {
        $this->returns = $node->returns;
    }

    /**
     * @param array<string, mixed> $bindings
     * @return Result<Option<mixed>, Throwable>
     */
    public function __invoke(array $bindings = []): Result
    {
        return $this->call($bindings);
    }

    /**
     * Invoke the program with the given bindings. Declared bindings pass
     * the boundary first; evaluation trusts everything past it.
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

        return ($this->node->evaluate)(new Runtime($admitted->unwrap(), $this->inspector));
    }

    /**
     * The boundary: every declared binding passes through its declared type
     * (coerce or assert, per policy), and every undeclared key is stripped —
     * the declaration list is the program's complete public signature.
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

        return Ok(new Bindings($overlay));
    }
}
