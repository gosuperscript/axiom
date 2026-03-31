<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Monads\Result\Result;

use Psl\Vec;

use function Superscript\Monads\Result\Ok;

final readonly class HasOverloader implements OperatorOverloader
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return $operator === 'has' && is_array($left);
    }

    /**
     * @param list<string|null> $left
     * @param list<string|null>|string|null $right
     * @return Result<bool, never>
     */
    public function evaluate(mixed $left, mixed $right, string $operator): Result
    {
        $left = Vec\filter_nulls($left);

        $right = Vec\filter_nulls(is_array($right) ? $right : [$right]);

        if ($right === []) {
            return Ok(false);
        }

        return Ok(array_intersect($right, $left) === $right);
    }
}
