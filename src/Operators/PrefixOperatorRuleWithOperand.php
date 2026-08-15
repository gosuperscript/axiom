<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\ErrorType;
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

    /** A rule cannot return the compiler's mark for a node that failed; see {@see PrefixOperatorRuleBuilder::takes()}. */
    public function returns(Type $returnType): PrefixOperatorRuleWithReturn
    {
        ErrorType::refuseAuthored($returnType, 'the return type of an operator rule');

        return new PrefixOperatorRuleWithReturn($this->operator, $this->operand, $returnType, $this->identifier);
    }
}
