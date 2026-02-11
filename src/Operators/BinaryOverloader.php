<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Monads\Result\Result;
use function Superscript\Monads\Result\attempt;

final readonly class BinaryOverloader implements OperatorOverloader
{
    private const operators = ['+', '-', '*', '/'];

    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return is_numeric($left) && is_numeric($right) && in_array($operator, self::operators);
    }

    /**
     * @param numeric $left
     * @param numeric $right
     * @param value-of<self::operators> $operator
     * @return Result<int|float, \Throwable>
     */
    public function evaluate(mixed $left, mixed $right, string $operator): Result
    {
        return attempt(fn () => match ($operator) {
            '+' => $left + $right,
            '-' => $left - $right,
            '*' => $left * $right,
            '/' => $left / $right,
        });
    }
}
