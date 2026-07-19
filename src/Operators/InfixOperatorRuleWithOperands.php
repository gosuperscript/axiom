<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;

/**
 * Stage two: operand types declared, the return type comes next.
 */
final readonly class InfixOperatorRuleWithOperands
{
    public function __construct(
        private string $operator,
        private Type $left,
        private Type $right,
        private ?string $identifier = null,
    ) {}

    public function returns(Type $returnType): InfixOperatorRuleWithReturn
    {
        return new InfixOperatorRuleWithReturn($this->operator, $this->left, $this->right, $returnType, $this->identifier);
    }
}
