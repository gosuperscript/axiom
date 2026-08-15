<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;

/**
 * Stage two: operand type declared, the return type comes next.
 */
final readonly class PrefixOperatorRuleWithOperand
{
    public function __construct(
        private string $operator,
        private Type $operand,
        private ?string $identifier = null,
    ) {}

    public function returns(Type $returnType): PrefixOperatorRuleWithReturn
    {
        return new PrefixOperatorRuleWithReturn($this->operator, $this->operand, $returnType, $this->identifier);
    }
}
