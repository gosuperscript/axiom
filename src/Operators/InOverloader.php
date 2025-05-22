<?php

declare(strict_types=1);

namespace Superscript\Abacus\Operators;

use function Psl\Type\mixed_vec;

class InOverloader implements OperatorOverloader
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return $operator === 'in' && is_array($right);
    }

    public function evaluate(mixed $left, mixed $right, string $operator): mixed
    {
        $right = mixed_vec()->coerce($right);
        return is_array($left) ? array_intersect($left, $right) === $left : in_array($left, $right);
    }
}
