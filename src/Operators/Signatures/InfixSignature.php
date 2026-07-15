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
 * A dispatch-table row: one declaration of operand ownership, one verdict.
 * Resolution is admissibility against the declared operand types; success
 * carries the declared return type and the declared evaluation, so the
 * static and runtime semantics are one statement. The closure only ever
 * sees values of the operand types it declared — the compiler proved them.
 *
 * The operand types are public so Dialect composition can refuse two rows
 * for the same operator with overlapping operand types — ambiguity is a
 * construction error, never a precedence question.
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
