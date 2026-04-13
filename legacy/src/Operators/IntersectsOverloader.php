<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Monads\Result\Result;

use Psl\Vec;

use function Superscript\Monads\Result\Ok;

class IntersectsOverloader implements OperatorOverloader
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return $operator === 'intersects';
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
}
