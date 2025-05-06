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

final readonly class BinaryOverloader implements OperatorOverloader
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return is_numeric($left) && is_numeric($right) && in_array($operator, ['+', '-', '*', '/']);
    }

    public function evaluate(mixed $left, mixed $right, string $operator): mixed
    {
        $left = num()->coerce($left);
        $right = num()->coerce($right);

        return match ($operator) {
            '+' => $left + $right,
            '-' => $left - $right,
            '*' => $left * $right,
            '/' => $left / $right,
            default => throw new UnhandledMatchError("Operator [$operator] is not supported."),
        };
    }
}