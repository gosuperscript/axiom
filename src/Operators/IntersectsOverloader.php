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

use Psl\Vec;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

class IntersectsOverloader implements OperatorOverloader
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return $operator === 'intersects'
            && ($left === null || is_scalar($left) || (is_array($left) && array_is_list($left)))
            && ($right === null || is_scalar($right) || (is_array($right) && array_is_list($right)));
    }

    /**
     * @param list<string|null>|string|null $left
     * @param list<string|null>|string|null $right
     * @param 'intersects' $operator
     * @return Result<bool, never>
     */
    public function evaluate(mixed $left, mixed $right, string $operator): Result
    {
        $left = Vec\filter_nulls(is_array($left) ? $left : [$left]);
        $right = Vec\filter_nulls(is_array($right) ? $right : [$right]);

        return Ok(count(array_intersect($left, $right)) > 0);
    }

    public function handles(string $operator): bool
    {
        return $operator === 'intersects';
    }

    /**
     * Either side may be a list or a scalar; absence is tolerated (the
     * runtime wraps and filters). The element types must overlap.
     *
     * @return Result<Type, TypeMismatch>
     */
    public function typeOf(string $operator, Type $left, Type $right): Result
    {
        if (!$this->handles($operator)) {
            return Err(new TypeMismatch(sprintf('Intersection does not handle [%s].', $operator)));
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

        if ($elementLeft instanceof NeverShape || $elementRight instanceof NeverShape) {
            return Ok(new BooleanType());
        }

        return TypeRelations::shapesOverlap($elementLeft, $elementRight)
            ->map(fn() => new BooleanType())
            ->mapErr(fn(TypeMismatch $cause) => new TypeMismatch(
                sprintf('[%s] between %s and %s can never hold.', $operator, TypeDescriber::describe($left), TypeDescriber::describe($right)),
                [$cause],
                dead: true,
            ));
    }
}
