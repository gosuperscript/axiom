<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators\Signatures;

use Superscript\Axiom\Types\Type;

/**
 * Stage one of {@see \Superscript\Axiom\Operators\Operator::infix()}: the
 * operator is chosen, the operand types come next. Each stage is a distinct
 * value, so a half-declared signature is unrepresentable rather than
 * validated.
 */
final readonly class InfixSignatureBuilder
{
    public function __construct(private string $operator) {}

    public function signature(Type $left, Type $right): InfixSignatureWithOperands
    {
        return new InfixSignatureWithOperands($this->operator, $left, $right);
    }
}
