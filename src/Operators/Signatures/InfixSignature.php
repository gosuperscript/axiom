<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators\Signatures;

use Closure;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * A dispatch-table row: this operator, over these operand types, returns
 * this type via this closure. Resolution checks the incoming operand
 * types against the declared slots (TypeRelations::admits) and, on a
 * match, returns the declared return type with the declared evaluation.
 * The closure only ever sees values of the operand types it declared —
 * the compiler proved them — so it may take natively typed parameters.
 *
 * The operand types are public so Dialect construction can refuse two
 * rows for the same operator that admit a common operand type — ambiguity
 * is a construction error, never a precedence question.
 */
final readonly class InfixSignature implements OperatorOverloader
{
    public function __construct(
        public string $operator,
        public Type $left,
        public Type $right,
        public Type $returns,
        private Closure $evaluation,
    ) {}

    /** @return Result<ResolvedOperation, TypeMismatch> */
    public function resolve(string $operator, Type $left, Type $right): Result
    {
        if ($operator !== $this->operator) {
            return Err(new TypeMismatch(
                sprintf('The [%s] signature does not resolve [%s].', $this->operator, $operator),
                unhandled: true,
            ));
        }

        $causes = [];

        foreach ([[$left, $this->left], [$right, $this->right]] as [$operand, $slot]) {
            $admitted = TypeRelations::admits($operand, $slot);

            if ($admitted->isErr()) {
                $causes[] = $admitted->unwrapErr();
            }
        }

        if ($causes !== []) {
            return Err(new TypeMismatch(
                sprintf(
                    '[%s] expects %s and %s; got %s and %s.',
                    $this->operator,
                    TypeDescriber::describe($this->left),
                    TypeDescriber::describe($this->right),
                    TypeDescriber::describe($left),
                    TypeDescriber::describe($right),
                ),
                $causes,
            ));
        }

        return Ok(new ResolvedOperation($this->returns, $this->evaluation));
    }
}
