<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\NullType;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Option\None;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Option\Some;
use Superscript\Monads\Result\Result;
use function Superscript\Monads\Result\Ok;

final readonly class NullOverloader implements OperatorOverloader
{
    private const array operators = ['+', '-', '*', '/'];

    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return $left === null && $right === null && in_array($operator, self::operators, strict: true);
    }

    public function inferType(Type $left, Type $right, string $operator): Option
    {
        if (! in_array($operator, self::operators, strict: true)) {
            return new None();
        }

        if ($left instanceof NullType && $right instanceof NullType) {
            return new Some(new NullType());
        }

        return new None();
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
