<?php

namespace Superscript\Abacus\Operators;

use UnhandledMatchError;

final readonly class DefaultOverloader implements OperatorOverloader
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return ! is_object($left) && ! is_object($right) && in_array($operator, ['+', '-', '*', '/', '>', '>=', '<', '<=', '=', '==', '!=', '===', '!==', 'in', 'has']);
    }

    public function evaluate(mixed $left, mixed $right, string $operator): mixed
    {
        return match ($operator) {
            '+' => $left + $right,
            '-' => $left - $right,
            '*' => $left * $right,
            '/' => $left / $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
            '<' => $left < $right,
            '<=' => $left <= $right,
            '=', '==' => $left == $right,
            '!=' => $left != $right,
            '===' => $left === $right,
            '!==' => $left !== $right,
            'in' => is_array($left) ? array_intersect($left, $right) === $left : in_array($left, $right ?? []),
            'has' => is_array($right) ? array_intersect($right, $left) === $right : in_array($right, $left ?? []),
            default => throw new UnhandledMatchError("Operator [$operator] is not supported."),
        };
    }
}