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
     * @return Result<bool, never>
     */
    public function evaluate(mixed $left, mixed $right, string $operator): Result
    {
        $leftArr = is_array($left) ? $left : [$left];
        $rightArr = is_array($right) ? $right : [$right];

        return Ok(array_any($leftArr, fn (mixed $item): bool => in_array($item, $rightArr)));
    }
}
