<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * Set intersection: either side may be a list or a scalar, absence is
 * tolerated (the evaluation wraps and filters), and the element types must
 * overlap. Judgment and evaluation are both {@see SetOperands} — the same
 * value equality as membership, never PHP's string-comparing
 * array_intersect.
 */
final readonly class IntersectsOverloader implements OperatorOverloader
{
    /** @return Result<ResolvedOperation, TypeMismatch> */
    public function resolve(string $operator, Type $left, Type $right): Result
    {
        if ($operator !== 'intersects') {
            return Err(new TypeMismatch(sprintf('Intersection does not resolve [%s].', $operator), unhandled: true));
        }

        $elementLeft = SetOperands::elements($left);
        $elementRight = SetOperands::elements($right);

        if ($elementLeft === null || $elementRight === null) {
            $offender = $elementLeft === null ? $left : $right;

            return Err(new TypeMismatch(sprintf(
                '[%s] requires lists or scalars; got %s.',
                $operator,
                TypeDescriber::describe($offender),
            )));
        }

        $operation = new ResolvedOperation(
            new BooleanType(),
            static fn(mixed $left, mixed $right): bool => SetOperands::anyShared($left, $right),
        );

        if ($elementLeft instanceof NeverShape || $elementRight instanceof NeverShape) {
            return Ok($operation);
        }

        return TypeRelations::shapesOverlap($elementLeft, $elementRight)
            ->map(fn() => $operation)
            ->mapErr(fn(TypeMismatch $cause) => new TypeMismatch(
                sprintf('[%s] between %s and %s can never hold.', $operator, TypeDescriber::describe($left), TypeDescriber::describe($right)),
                [$cause],
                dead: true,
            ));
    }
}
