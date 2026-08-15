<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\ErrorType;
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

    /** A rule cannot return the compiler's mark for a node that failed; see {@see InfixOperatorRuleBuilder::takes()}. */
    public function returns(Type $returnType): InfixOperatorRuleWithReturn
    {
        ErrorType::refuseAuthored($returnType, 'the return type of an operator rule');

        return new InfixOperatorRuleWithReturn($this->operator, $this->left, $this->right, $returnType, $this->identifier);
    }
}
