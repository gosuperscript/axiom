<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Monads\Result\Result;
use function Superscript\Monads\Result\Ok;

class InOverloader implements OperatorOverloader
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return $operator === 'in' && is_array($right);
    }

    /**
     * @param array<mixed> $right
     * @param 'in' $operator
     * @return Result<bool, never>
     */
    public function evaluate(mixed $left, mixed $right, string $operator): Result
    {
        if (is_array($left)) {
            return Ok(array_all($left, fn (mixed $item): bool => in_array($item, $right)));
        }

        return Ok(in_array($left, $right));
    }
}
