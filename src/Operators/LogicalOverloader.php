<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Option\None;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Option\Some;
use Superscript\Monads\Result\Result;
use function Superscript\Monads\Result\Ok;

final readonly class LogicalOverloader implements OperatorOverloader
{
    private const operators = ['&&', '||', 'xor'];

    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return in_array($operator, self::operators) && is_bool($left) && is_bool($right);
    }

    public function inferType(Type $left, Type $right, string $operator): Option
    {
        if (! in_array($operator, self::operators, strict: true)) {
            return new None();
        }

        if ($left instanceof BooleanType && $right instanceof BooleanType) {
            return new Some(new BooleanType());
        }

        return new None();
    }

    /**
     * @param value-of<self::operators> $operator
     * @return Result<bool, never>
     */
    public function evaluate(mixed $left, mixed $right, string $operator): Result
    {
        return Ok(match ($operator) {
            '&&' => $left && $right,
            '||' => $left || $right,
            'xor' => $left xor $right,
        });
    }
}
