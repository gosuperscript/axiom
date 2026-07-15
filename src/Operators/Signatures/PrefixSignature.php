<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators\Signatures;

use Closure;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Operators\UnaryOverloader;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;

/**
 * The unary twin of {@see InfixSignature}: this operator, over this
 * operand type, returns this type via this closure. The operand type is
 * public for the same reason — Dialect construction refuses two rows for
 * the same operator that admit a common operand type.
 */
final readonly class PrefixSignature implements UnaryOverloader
{
    public function __construct(
        public string $operator,
        public Type $operand,
        public Type $returns,
        private Closure $evaluation,
    ) {}

    /** @return Result<ResolvedOperation, TypeMismatch> */
    public function resolve(string $operator, Type $operand): Result
    {
        if ($operator !== $this->operator) {
            return Err(new TypeMismatch(
                sprintf('The [%s] signature does not resolve [%s].', $this->operator, $operator),
                unhandled: true,
            ));
        }

        return TypeRelations::admits($operand, $this->operand)
            ->map(fn() => new ResolvedOperation($this->returns, $this->evaluation))
            ->mapErr(fn(TypeMismatch $cause) => new TypeMismatch(
                sprintf(
                    '[%s] expects %s; got %s.',
                    $this->operator,
                    TypeDescriber::describe($this->operand),
                    TypeDescriber::describe($operand),
                ),
                [$cause],
            ));
    }
}
