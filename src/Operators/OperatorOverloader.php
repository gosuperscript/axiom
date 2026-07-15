<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

/**
 * A binary operator rule: one verdict carrying both semantics. resolve()
 * is asked once, with types, at compile time — a compiled program never
 * dispatches on values, so there is no second face to keep in agreement.
 */
interface OperatorOverloader
{
    /**
     * Does this rule own $operator over these operand types — and if so,
     * what does it return and how does it evaluate?
     *
     * Contract (certification): Ok(ResolvedOperation) means this rule
     * certifies these operand types — the evaluation is total over every
     * value pair of them (value-dependent partiality remains: division by
     * zero is a runtime error, certified or not) and its result inhabits
     * the returned type. Err(TypeMismatch) refuses: mark the mismatch
     * `unhandled` when the operator itself is not this rule's (so composed
     * diagnostics can skip it), and `dead` when the operation is statically
     * meaningless though well-formed (a comparison that can never hold).
     *
     * Absence is THIS rule's concern: a rule whose evaluation cannot take
     * null refuses Option operands (which falls out of admits()); a rule
     * that tolerates absence resolves them and its closure handles null.
     * There is no Unknown hole: an Unknown operand is refused — the author
     * bridges with Coerce or Ascription.
     *
     * @return Result<ResolvedOperation, TypeMismatch>
     */
    public function resolve(string $operator, Type $left, Type $right): Result;
}
