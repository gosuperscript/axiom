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

final readonly class ComparisonOverloader implements OperatorOverloader
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return in_array($operator, ['=', '==', '===', '!=', '!==', '<', '<=', '>', '>=']);
    }

    public function evaluate(mixed $left, mixed $right, string $operator): mixed
    {
        return match ($operator) {
            '=', '==' => $left == $right,
            '===' => $left === $right,
            '!=' => $left != $right,
            '!==' => $left !== $right,
            '<' => $left < $right,
            '<=' => $left <= $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
            default => throw new UnhandledMatchError("Operator [$operator] is not supported."),
        };
    }
}