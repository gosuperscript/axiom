<?php

declare(strict_types=1);

namespace Superscript\Abacus\Operators;

use UnhandledMatchError;

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
