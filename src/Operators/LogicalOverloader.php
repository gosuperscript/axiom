<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Monads\Result\Result;
use function Superscript\Monads\Result\Ok;

final readonly class LogicalOverloader implements OperatorOverloader
{
    private const operators = ['&&', '||', 'xor'];

    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return in_array($operator, self::operators) && is_bool($left) && is_bool($right);
    }

    /**
     * @param value-of<self::operators> $operator
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
