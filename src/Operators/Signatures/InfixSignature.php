<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators\Signatures;

use Closure;
use Superscript\Axiom\Operators\BinaryOperatorRule;
use Superscript\Axiom\Operators\OperatorResolution;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Operators\UnsupportedOperation;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeRelations;

/**
 * One binary operator rule, declared as data: "the operator [-], taking
 * (Date, Period), returns Date, computed by this closure". Built via the
 * Operator::infix() builder.
 *
 * resolve() answers with a resolution when both operand types fit the
 * declared slots; the answer carries the
 * declared return type and closure. Because the compiler only ever calls
 * the closure with values of the declared operand types, the closure may
 * declare native parameter types (fn (Date $d, Period $p) => ...).
 *
 * The operator and operand types are public properties because Dialect
 * reads them at construction to detect two rows that could both match
 * the same expression, which it refuses as ambiguous.
 */
final readonly class InfixSignature implements BinaryOperatorRule
{
    public function __construct(
        public string $operator,
        public Type $left,
        public Type $right,
        public Type $returns,
        private Closure $evaluation,
    ) {}

    public function operator(): string
    {
        return $this->operator;
    }

    public function resolve(Type $left, Type $right): OperatorResolution
    {
        $causes = [];

        foreach ([[$left, $this->left], [$right, $this->right]] as [$operand, $slot]) {
            $admitted = TypeRelations::admits($operand, $slot);

            if ($admitted->isErr()) {
                $causes[] = $admitted->unwrapErr();
            }
        }

        if ($causes !== []) {
            return new UnsupportedOperation(
                sprintf(
                    '[%s] expects %s and %s; got %s and %s.',
                    $this->operator,
                    TypeDescriber::describe($this->left),
                    TypeDescriber::describe($this->right),
                    TypeDescriber::describe($left),
                    TypeDescriber::describe($right),
                ),
                $causes,
            );
        }

        return new ResolvedOperation($this->returns, $this->evaluation);
    }
}
