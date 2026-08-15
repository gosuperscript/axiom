<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use InvalidArgumentException;
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

    public function takes(Type $left, Type $right): InfixOperatorRuleWithOperands
    {
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
