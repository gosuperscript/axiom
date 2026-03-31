<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Monads\Result\Result;

use function Psl\Type\vec;
use function Psl\Type\string;
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
        $left = vec(string())->coerce(is_array($left) ? $left : [$left]);
        $right = vec(string())->coerce($right);

        return Ok(array_intersect($left, $right) === $left);
    }
}
