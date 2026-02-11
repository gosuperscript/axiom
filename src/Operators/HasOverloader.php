<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Monads\Result\Result;
use function Psl\Type\mixed_vec;
use function Superscript\Monads\Result\Ok;

final readonly class HasOverloader implements OperatorOverloader
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return $operator === 'has' && is_array($left);
    }

    public function evaluate(mixed $left, mixed $right, string $operator): Result
    {
        $left = mixed_vec()->coerce($left);
        return Ok(is_array($right) ? array_intersect($right, $left) === $right : in_array($right, $left));
    }
}
