<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\Shapes\OpaqueShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Shapes\ShapeDomain;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeRelations;

/** Equality semantics configured for one concrete operator alias. */
final readonly class Equality implements BinaryOperatorRule
{
    public function __construct(
        private string $operator,
        private bool $negated,
    ) {}

    public function operator(): string
    {
        return $this->operator;
    }

    public function resolve(Type $left, Type $right): OperatorResolution
    {
        foreach (['left' => $left, 'right' => $right] as $side => $operand) {
            if (!ShapeDomain::all($operand->shape(), fn(Shape $leaf) => !$leaf instanceof OpaqueShape)) {
                return new UnsupportedOperation(sprintf(
                    '[%s] does not claim the %s operand: %s has object or Unknown values; object equality belongs to the rule that owns the type, and an Unknown value is bridged with Coerce or Ascription first.',
                    $this->operator,
                    $side,
                    TypeDescriber::describe($operand),
                ));
            }
        }

        $overlap = TypeRelations::overlaps($left, $right);

        if ($overlap->isErr()) {
            return new DeadOperation(sprintf(
                '[%s] between %s and %s is constant: it %s.',
                $this->operator,
                TypeDescriber::describe($left),
                TypeDescriber::describe($right),
                $this->negated ? 'always holds' : 'can never hold',
            ), [$overlap->unwrapErr()]);
        }

        return new ResolvedOperation(
            new BooleanType(),
            fn(mixed $left, mixed $right) => $this->negated
                ? !ValueEquality::equals($left, $right)
                : ValueEquality::equals($left, $right),
        );
    }
}
