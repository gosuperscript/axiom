<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

use Psl\Vec;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

final readonly class HasOverloader implements OperatorOverloader
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return $operator === 'has'
            && is_array($left) && array_is_list($left)
            && ($right === null || is_scalar($right) || (is_array($right) && array_is_list($right)));
    }

    /**
     * Membership is value equality ({@see ValueEquality}) — never PHP's
     * array_intersect, whose string comparison juggles types (true in [1]
     * must be false).
     *
     * @param list<mixed> $left
     * @return Result<bool, never>
     */
    public function evaluate(mixed $left, mixed $right, string $operator): Result
    {
        $left = Vec\filter_nulls($left);

        $right = Vec\filter_nulls(is_array($right) ? $right : [$right]);

        if ($right === []) {
            return Ok(false);
        }

        return Ok(array_all($right, fn(mixed $needle) => ValueEquality::contains($left, $needle)));
    }

    public function handles(string $operator): bool
    {
        return $operator === 'has';
    }

    /**
     * The left side must be a present list; the right side is a needle (or
     * a list of needles), absence tolerated. Element types must overlap —
     * membership that can never hold is dead code. A Never element (the
     * empty list literal) is vacuously legal: constant false, never an error.
     *
     * @return Result<Type, TypeMismatch>
     */
    public function typeOf(string $operator, Type $left, Type $right): Result
    {
        if (!$this->handles($operator)) {
            return Err(new TypeMismatch(sprintf('Membership does not handle [%s].', $operator)));
        }

        return SetOperands::membership($left, $right, $operator, listSide: 'left');
    }
}
