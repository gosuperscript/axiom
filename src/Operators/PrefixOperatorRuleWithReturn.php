<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;

/**
 * Stage three: the final step completes the rule.
 */
final readonly class PrefixOperatorRuleWithReturn
{
    public function __construct(
        private string $operator,
        private Type $operand,
        private Type $returnType,
        private ?string $identifier = null,
    ) {}

    /**
     * The closure receives a value of the declared operand type — the
     * compiler proved it, so it may take it natively. A plain return value
     * is wrapped in Ok; a returned Result passes through; a throw
     * propagates ({@see \Superscript\Axiom\Operators\ResolvedOperation}).
     */
    public function evaluatesWith(callable $evaluation): PrefixOperatorRule
    {
        return new PrefixOperatorRule($this->operator, $this->operand, $this->returnType, $evaluation(...), $this->identifier);
    }
}
