<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

/**
 * A binary operator rule owning both semantics: evaluate() is the runtime
 * face, typeOf() the static face. Co-location is the drift guarantee — a
 * semantics change and its typing rule are one diff.
 *
 * Honesty contract: supportsOverloading() must claim only values this rule
 * owns. Operator-only dispatch shadows every rule listed after it and hides
 * semantics from the static layer.
 */
interface OperatorOverloader
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool;

    /** @return Result<mixed, \Throwable> */
    public function evaluate(mixed $left, mixed $right, string $operator): Result;

    /**
     * Which operators this rule types — the static face of the operator
     * dimension of supportsOverloading().
     */
    public function handles(string $operator): bool;

    /**
     * The return type for operands of these types.
     *
     * Contract (certification): Ok(T) means this rule certifies these
     * operand types — every value pair it claims and successfully evaluates
     * produces a T (value-dependent partiality remains: division by zero is
     * a runtime error, certified or not), and the values it does not claim
     * are another rule's to cover (within a composed dialect, total coverage
     * is what the agreement harness checks). Err(TypeMismatch) means this
     * rule does not certify these operand types: values of them would fall
     * outside its runtime claims, or the operation is statically meaningless
     * though runtime-tolerated (a dead mismatch — see TypeMismatch::$dead).
     *
     * Absence is THIS rule's concern: a rule whose runtime rejects null
     * refuses Option operands (which falls out of admits()); a rule that
     * substitutes zero admits them; a rule whose result can be absent says
     * so in its return type. The only sanctioned unsoundness is Unknown:
     * gradual admission deliberately certifies what it cannot check.
     *
     * @return Result<Type, TypeMismatch>
     */
    public function typeOf(string $operator, Type $left, Type $right): Result;
}
