<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators\Signatures;

use Superscript\Axiom\Types\Type;

/**
 * Stage three: the final step compiles the row.
 */
final readonly class PrefixSignatureWithReturn
{
    public function __construct(
        private string $operator,
        private Type $operand,
        private Type $returns,
    ) {}

    /**
     * The closure receives a value of the declared operand type — the
     * compiler proved it, so it may take it natively. A plain return value
     * is wrapped in Ok; a returned Result passes through; a throw
     * propagates ({@see \Superscript\Axiom\Operators\ResolvedOperation}).
     */
    public function evaluate(callable $evaluation): PrefixSignature
    {
        return new PrefixSignature($this->operator, $this->operand, $this->returns, $evaluation(...));
    }
}
