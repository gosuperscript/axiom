<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

use Psl\Vec;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

class InOverloader implements OperatorOverloader
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return $operator === 'in'
            && is_array($right) && array_is_list($right)
            && ($left === null || is_scalar($left) || (is_array($left) && array_is_list($left)));
    }

    /**
     * @param list<string|null>|string|null $left
     * @param list<string|null> $right
     * @param 'in' $operator
     * @return Result<bool, never>
     */
    public function evaluate(mixed $left, mixed $right, string $operator): Result
    {
        $left = Vec\filter_nulls(is_array($left) ? $left : [$left]);
        $right = Vec\filter_nulls($right);

        if ($left === []) {
            return Ok(false);
        }

        return Ok(array_intersect($left, $right) === $left);
    }

    public function handles(string $operator): bool
    {
        return $operator === 'in';
    }

    /**
     * The mirror of [has]: the right side must be a present list, the left
     * a needle (or list of needles), absence tolerated, element overlap
     * required.
     *
     * @return Result<Type, TypeMismatch>
     */
    public function typeOf(string $operator, Type $left, Type $right): Result
    {
        if (!$this->handles($operator)) {
            return Err(new TypeMismatch(sprintf('Membership does not handle [%s].', $operator)));
        }

        return SetOperands::membership($right, $left, $operator, listSide: 'right');
    }
}
