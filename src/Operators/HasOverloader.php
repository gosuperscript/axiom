<?php

namespace Superscript\Abacus\Operators;

use UnhandledMatchError;
use function Psl\Type\float;
use function Psl\Type\i64;
use function Psl\Type\mixed_dict;
use function Psl\Type\mixed_vec;
use function Psl\Type\num;
use function Psl\Type\scalar;
use function Psl\Type\union;
use function Psl\Type\vec;

final readonly class HasOverloader implements OperatorOverloader
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return $operator === 'has' && is_array($left);
    }

    public function evaluate(mixed $left, mixed $right, string $operator): mixed
    {
        $left = mixed_vec()->coerce($left);
        return is_array($right) ? array_intersect($right, $left) === $right : in_array($right, $left);
    }
}