<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

/**
 * A unary operator rule, mirroring {@see OperatorOverloader}: the compiler
 * asks resolve() once per node, with the operand type, and the program
 * runs the returned evaluation directly.
 *
 * Absence never reaches a unary rule: the compiler resolves against the
 * present operand type (Boolean, not Option<Boolean>) and short-circuits
 * absent operands around the rule, so !Option<Boolean> is Option<Boolean>
 * and the evaluation only ever sees present values.
 */
interface UnaryOverloader
{
    /**
     * The contract of {@see OperatorOverloader::resolve()}, one operand:
     * Ok promises the evaluation works for every value of the operand type
     * and its result is a value of the returned type.
     *
     * @return Result<ResolvedOperation, TypeMismatch>
     */
    public function resolve(string $operator, Type $operand): Result;
}
