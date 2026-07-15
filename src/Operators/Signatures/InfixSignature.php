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
 * One binary operator rule, declared as data: "the operator [-], taking
 * (Date, Period), returns Date, computed by this closure". Built via the
 * Operator::infix() builder.
 *
 * resolve() answers Ok when the asked-about operator is this row's and
 * both operand types fit the declared slots; the answer carries the
 * declared return type and closure. Because the compiler only ever calls
 * the closure with values of the declared operand types, the closure may
 * declare native parameter types (fn (Date $d, Period $p) => ...).
 *
 * The operator and operand types are public properties because Dialect
 * reads them at construction to detect two rows that could both match
 * the same expression, which it refuses as ambiguous.
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
