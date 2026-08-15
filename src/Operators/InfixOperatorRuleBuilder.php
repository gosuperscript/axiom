<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use InvalidArgumentException;
use Superscript\Axiom\Types\ErrorType;
use Superscript\Axiom\Types\Type;

/**
 * Stage one of {@see Operator::infix()}: the operator is chosen, and the
 * operand types come next. Each stage is a distinct value, so a
 * half-declared rule is unrepresentable rather than validated.
 */
final readonly class InfixOperatorRuleBuilder
{
    public function __construct(
        private string $operator,
        private ?string $identifier = null,
    ) {}

    public function identifiedBy(string $identifier): self
    {
        if ($identifier === '') {
            throw new InvalidArgumentException('An operator rule identifier cannot be empty.');
        }

        return new self($this->operator, $identifier);
    }

    /**
     * An operand type is refused the compiler's mark for a node that failed:
     * an operation over a failed operand is absorbed before any rule is
     * looked at, so a rule declaring one could never fire. A rule that can
     * never match is a confusing way to learn about the mistake, so the
     * declaration says so instead.
     */
    public function takes(Type $left, Type $right): InfixOperatorRuleWithOperands
    {
        ErrorType::refuseAuthored($left, 'the left operand of an operator rule');
        ErrorType::refuseAuthored($right, 'the right operand of an operator rule');

        return new InfixOperatorRuleWithOperands($this->operator, $left, $right, $this->identifier);
    }

    /**
     * @template TLeft of Type
     * @template TRight of Type
     * @param class-string<TLeft> $left
     * @param class-string<TRight> $right
     * @return InfixOperatorRuleWithMatchingTypes<TLeft, TRight>
     */
    public function matching(string $left, string $right): InfixOperatorRuleWithMatchingTypes
    {
        return new InfixOperatorRuleWithMatchingTypes($this->operator, $left, $right, $this->identifier);
    }
}
