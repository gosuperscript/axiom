<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators\Signatures;

use Closure;
use Superscript\Axiom\Operators\OperatorResolution;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Operators\UnaryOperatorRule;
use Superscript\Axiom\Operators\UnsupportedOperation;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeRelations;

/**
 * The unary twin of {@see InfixSignature}: this operator, over this
 * operand type, returns this type via this closure. The operand type is
 * public for the same reason — Dialect construction refuses two rows for
 * the same operator that admit a common operand type.
 */
final readonly class PrefixSignature implements UnaryOperatorRule
{
    public function __construct(
        public string $operator,
        public Type $operand,
        public Type $returns,
        private Closure $evaluation,
    ) {}

    public function operator(): string
    {
        return $this->operator;
    }

    public function resolve(Type $operand): OperatorResolution
    {
        $admitted = TypeRelations::admits($operand, $this->operand);

        if ($admitted->isErr()) {
            return new UnsupportedOperation(sprintf(
                '[%s] expects %s; got %s.',
                $this->operator,
                TypeDescriber::describe($this->operand),
                TypeDescriber::describe($operand),
            ), [$admitted->unwrapErr()]);
        }

        return new ResolvedOperation($this->returns, $this->evaluation);
    }
}
