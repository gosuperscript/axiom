<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Monads\Result\Result;

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
        $left = array_filter($left, is_string(...));

        $right = array_filter(is_array($right) ? $right : [$right], is_string(...));

        if ($right === []) {
            return Ok(false);
        }

        return Ok(array_intersect($right, $left) === $right);
    }
}
