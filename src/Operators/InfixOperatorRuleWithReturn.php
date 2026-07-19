<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;

/**
 * Stage three: the final step completes the rule — there is no build() to
 * forget.
 */
final readonly class InfixOperatorRuleWithReturn
{
    public function __construct(
        private string $operator,
        private Type $left,
        private Type $right,
        private Type $returnType,
        private ?string $identifier = null,
    ) {}

    /**
     * The closure receives values of the declared operand types — the
     * compiler proved them, so it may take them natively (`fn (Carbon $l,
     * Period $r) => …`). A plain return value is wrapped in Ok; a returned
     * Result passes through (value-dependent partiality); a throw
     * propagates ({@see \Superscript\Axiom\Operators\ResolvedOperation}).
     */
    public function evaluatesWith(callable $evaluation): InfixOperatorRule
    {
        return new InfixOperatorRule($this->operator, $this->left, $this->right, $this->returnType, $evaluation(...), $this->identifier);
    }
}
