<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

/**
 * A unary operator rule, mirroring {@see OperatorOverloader}: one verdict,
 * both semantics, asked once at compile time.
 *
 * Absence never reaches a unary rule: the compiler resolves the rule
 * against the present operand type and wraps the compiled node with the
 * absence short-circuit, so optionality propagates structurally and the
 * resolved evaluation only ever sees present values.
 */
interface UnaryOverloader
{
    /**
     * The certification contract of OperatorOverloader::resolve(), one
     * operand: Ok means the evaluation is total over the operand type and
     * its result inhabits the returned type.
     *
     * @return Result<ResolvedOperation, TypeMismatch>
     */
    public function resolve(string $operator, Type $operand): Result;
}
