<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Monads\Result\Result;
use function Psl\Type\mixed_vec;
use function Psl\Type\vec;
use function Psl\Type\string;
use function Superscript\Monads\Result\Ok;

final readonly class HasOverloader implements OperatorOverloader
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return $operator === 'has' && is_array($left);
    }

    /** @return Result<bool, never> */
    public function evaluate(mixed $left, mixed $right, string $operator): Result
    {
        $left = mixed_vec()->coerce($left);

        if (is_array($right)) {
            $right = vec(string())->coerce($right);

            return Ok(array_intersect($right, vec(string())->coerce($left)) === $right);
        }

        return Ok(in_array($right, $left));
    }
}
