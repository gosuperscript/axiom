<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;

/**
 * Typed prefilter for a computed binary rule. Non-matching Type classes are
 * refused automatically; the resolver only handles its own concrete types.
 *
 * @template TLeft of Type
 * @template TRight of Type
 */
final readonly class InfixOperatorRuleWithMatchingTypes
{
    /**
     * @param class-string<TLeft> $left
     * @param class-string<TRight> $right
     */
    public function __construct(
        private string $operator,
        private string $left,
        private string $right,
        private ?string $identifier = null,
    ) {}

    /** @param callable(TLeft, TRight): OperatorResolution $resolve */
    public function resolvesWith(callable $resolve): MatchingInfixOperatorRule
    {
        return new MatchingInfixOperatorRule(
            $this->operator,
            $this->left,
            $this->right,
            $resolve(...),
            $this->identifier,
        );
    }
}
