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
     */
    public function evaluate(mixed $left, mixed $right, string $operator): Result
    {
        return Ok(is_array($left) ? array_intersect($left, $right) === $left : in_array($left, $right));
    }
}
