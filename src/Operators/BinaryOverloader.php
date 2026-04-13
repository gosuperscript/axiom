<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Option\None;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Option\Some;
use Superscript\Monads\Result\Result;
use function Superscript\Monads\Result\attempt;

final readonly class BinaryOverloader implements OperatorOverloader
{
    private const operators = ['+', '-', '*', '/'];

    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return is_numeric($left) && is_numeric($right) && in_array($operator, self::operators);
    }

    public function inferType(Type $left, Type $right, string $operator): Option
    {
        if (! in_array($operator, self::operators, strict: true)) {
            return new None();
        }

        if ($left instanceof NumberType && $right instanceof NumberType) {
            return new Some(new NumberType());
        }

        return new None();
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
