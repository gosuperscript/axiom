<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Option\None;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Option\Some;
use Superscript\Monads\Result\Result;

use Psl\Vec;

use function Superscript\Monads\Result\Ok;

class InOverloader implements OperatorOverloader
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return $operator === 'in' && is_array($right);
    }

    public function inferType(Type $left, Type $right, string $operator): Option
    {
        if ($operator !== 'in') {
            return new None();
        }

        if ($right instanceof ListType) {
            return new Some(new BooleanType());
        }

        return new None();
    }

    /**
     * @param list<string|null>|string|null $left
     * @param list<string|null> $right
     * @param 'in' $operator
     * @return Result<bool, never>
     */
    public function evaluate(mixed $left, mixed $right, string $operator): Result
    {
        $left = Vec\filter_nulls(is_array($left) ? $left : [$left]);
        $right = Vec\filter_nulls($right);

        if ($left === []) {
            return Ok(false);
        }

        return Ok(array_intersect($left, $right) === $left);
    }
}
