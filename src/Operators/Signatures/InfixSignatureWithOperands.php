<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators\Signatures;

use Superscript\Axiom\Types\Type;

/**
 * Stage two: operand types declared, the return type comes next.
 */
final readonly class InfixSignatureWithOperands
{
    public function __construct(
        private string $operator,
        private Type $left,
        private Type $right,
    ) {}

    public function returns(Type $type): InfixSignatureWithReturn
    {
        return new InfixSignatureWithReturn($this->operator, $this->left, $this->right, $type);
    }
}
