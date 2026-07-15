<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators\Signatures;

use Superscript\Axiom\Types\Type;

/**
 * Stage two: operand type declared, the return type comes next.
 */
final readonly class PrefixSignatureWithOperand
{
    public function __construct(
        private string $operator,
        private Type $operand,
    ) {}

    public function returns(Type $type): PrefixSignatureWithReturn
    {
        return new PrefixSignatureWithReturn($this->operator, $this->operand, $type);
    }
}
