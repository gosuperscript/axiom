<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Monads\Result\Result;
use function Superscript\Monads\Result\Ok;

final readonly class NullOverloader implements OperatorOverloader
{
    private const array operators = ['+', '-', '*', '/'];

    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return $left === null && $right === null && in_array($operator, self::operators, strict: true);
    }

    /**
     * @param value-of<self::operators> $operator
     * @return Result<null, never>
     */
    public function evaluate(mixed $left, mixed $right, string $operator): Result
    {
        return Ok(null);
    }
}
