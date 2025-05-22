<?php

declare(strict_types=1);

namespace Superscript\Abacus\Operators;

use UnhandledMatchError;

use function Psl\Type\num;

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
