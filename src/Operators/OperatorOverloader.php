<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

/**
 * A binary operator rule. The compiler asks resolve() once per operator
 * node, with the operand types; a compiled program then runs the returned
 * evaluation directly and never inspects a value's type again.
 */
interface OperatorOverloader
{
    /**
     * Does this rule own $operator over these operand types — and if so,
     * what does it return and how does it evaluate?
     *
     * Answering Ok(ResolvedOperation) is a promise: the evaluation works
     * for every value pair of these operand types and its result is a
     * value of the returned type. Nothing re-checks the result at runtime,
     * so a rule that only handles some values of a type must refuse the
     * type. (Value-dependent errors remain legal: division by zero returns
     * an Err, resolved or not.)
     *
     * Err(TypeMismatch) refuses. Mark the mismatch `unhandled` when the
     * operator itself is not this rule's — the manager keeps such refusals
     * out of aggregated diagnostics — and `dead` when the operation is
     * well-formed but statically meaningless (a comparison that can never
     * hold), which consumers render as a probable author bug.
     *
     * Absence is this rule's concern: a rule whose evaluation cannot take
     * null refuses Option operands (using admits() gives that for free); a
     * rule that tolerates absence resolves them and its closure handles
     * null. An Unknown operand is always refused — the author converts
     * with Coerce or claims a type with Ascription.
     *
     * @return Result<ResolvedOperation, TypeMismatch>
     */
    public function resolve(string $operator, Type $left, Type $right): Result;
}
