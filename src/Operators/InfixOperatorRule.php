<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Closure;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeRelations;

/**
 * One binary operator rule, declared as data: "the operator [-] takes
 * (Date, Period), returns Date, and evaluates with this closure". Built via
 * the Operator::infix() builder.
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
final readonly class InfixOperatorRule implements BinaryOperatorRule
{
    public function __construct(
        public string $operator,
        public Type $left,
        public Type $right,
        public Type $returnType,
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

        return new ResolvedOperation($this->returnType, $this->evaluation);
    }
}
