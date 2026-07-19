<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;

/** @template TOperand of Type */
final readonly class PrefixOperatorRuleWithMatchingType
{
    /** @param class-string<TOperand> $operand */
    public function __construct(
        private string $operator,
        private string $operand,
        private ?string $identifier = null,
    ) {}

    /** @param callable(TOperand): OperatorResolution $resolve */
    public function resolvesWith(callable $resolve): MatchingPrefixOperatorRule
    {
        return new MatchingPrefixOperatorRule(
            $this->operator,
            $this->operand,
            $resolve(...),
            $this->identifier,
        );
    }
}
