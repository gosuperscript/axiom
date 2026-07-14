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
     * The closure receives values both operand types asserted. A plain
     * return value is wrapped in Ok; a returned Result passes through; a
     * throw propagates (see {@see InfixSignature::evaluate()}).
     *
     * @param callable(mixed, mixed): mixed $evaluation
     */
    public function evaluate(callable $evaluation): InfixSignature
    {
        return new InfixSignature($this->operator, $this->left, $this->right, $this->returns, $evaluation(...));
    }
}
