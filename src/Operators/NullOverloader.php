<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

final readonly class NullOverloader implements OperatorOverloader
{
    private const array operators = ['+', '-', '*', '/'];

    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return $left === null && $right === null && in_array($operator, self::operators, strict: true);
    }

    /**
     * @param value-of<self::operators> $operator
     */
    public function evaluate(mixed $left, mixed $right, string $operator): mixed
    {
        return null;
    }
}
