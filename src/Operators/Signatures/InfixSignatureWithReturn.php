<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators\Signatures;

use Superscript\Axiom\Types\Type;

/**
 * Stage three: the final step compiles the row — there is no build() to
 * forget.
 */
final readonly class InfixSignatureWithReturn
{
    public function __construct(
        private string $operator,
        private Type $left,
        private Type $right,
        private Type $returns,
    ) {}

    /**
     * The closure receives values of the declared operand types — the
     * compiler proved them, so it may take them natively (`fn (Carbon $l,
     * Period $r) => …`). A plain return value is wrapped in Ok; a returned
     * Result passes through (value-dependent partiality); a throw
     * propagates ({@see \Superscript\Axiom\Operators\ResolvedOperation}).
     */
    public function evaluate(callable $evaluation): InfixSignature
    {
        return new InfixSignature($this->operator, $this->left, $this->right, $this->returns, $evaluation(...));
    }
}
