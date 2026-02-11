<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Monads\Result\Result;
use function Superscript\Monads\Result\Ok;

class IntersectsOverloader implements OperatorOverloader
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return $operator === 'intersects';
    }

    /**
     * @param 'in' $operator
     */
    public function evaluate(mixed $left, mixed $right, string $operator): Result
    {
        $left = is_array($left) ? $left : [$left];
        $right = is_array($right) ? $right : [$right];

        return Ok(count(array_intersect($left, $right)) > 0);
    }
}
