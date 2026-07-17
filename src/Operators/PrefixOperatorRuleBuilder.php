<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use InvalidArgumentException;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;

/**
 * Stage one of {@see Operator::prefix()}.
 */
final readonly class PrefixOperatorRuleBuilder
{
    public function __construct(private string $operator) {}

    /**
     * An Option operand is refused loudly: absence never reaches a unary
     * rule — the resolver short-circuits absent operands before any rule
     * runs and optionality propagates structurally — so a rule taking an
     * Option would declare a claim that can never fire.
     */
    public function takes(Type $operand): PrefixOperatorRuleWithOperand
    {
        if ($operand->shape() instanceof OptionShape) {
            throw new InvalidArgumentException(sprintf(
                'A prefix operator rule cannot take an Option operand (%s): absence never reaches a unary rule, so the claim could never fire. Declare the present type; optionality propagates structurally.',
                TypeDescriber::describe($operand),
            ));
        }

        return new PrefixOperatorRuleWithOperand($this->operator, $operand);
    }

    /**
     * @template TOperand of Type
     * @param class-string<TOperand> $operand
     * @return PrefixOperatorRuleWithMatchingType<TOperand>
     */
    public function matching(string $operand): PrefixOperatorRuleWithMatchingType
    {
        return new PrefixOperatorRuleWithMatchingType($this->operator, $operand);
    }
}
