<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeRelations;

/** Set intersection over scalar or list operands. */
final readonly class Intersects implements BinaryOperatorRule
{
    public function operator(): string
    {
        return 'intersects';
    }

    public function resolve(Type $left, Type $right): OperatorResolution
    {
        $elementLeft = SetOperands::elements($left);
        $elementRight = SetOperands::elements($right);

        if ($elementLeft === null || $elementRight === null) {
            $offender = $elementLeft === null ? $left : $right;

            return new UnsupportedOperation(sprintf(
                '[%s] requires lists or scalars; got %s.',
                $this->operator(),
                TypeDescriber::describe($offender),
            ));
        }

        $support = SetOperands::supportsValueEquality($left, $right, $this->operator());

        if ($support->isErr()) {
            $mismatch = $support->unwrapErr();

            return new UnsupportedOperation($mismatch->message, $mismatch->causes);
        }

        $operation = new ResolvedOperation(
            new BooleanType(),
            static fn(mixed $left, mixed $right): bool => SetOperands::anyShared($left, $right),
        );

        if ($elementLeft instanceof NeverShape || $elementRight instanceof NeverShape) {
            return $operation;
        }

        $overlap = TypeRelations::shapesOverlap($elementLeft, $elementRight);

        return $overlap->isOk()
            ? $operation
            : new DeadOperation(
                sprintf('[%s] between %s and %s can never hold.', $this->operator(), TypeDescriber::describe($left), TypeDescriber::describe($right)),
                [$overlap->unwrapErr()],
            );
    }
}
