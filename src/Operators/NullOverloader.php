<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * The degenerate null ∘ null → null rule. Statically it contributes no
 * admissibility beyond what Option operand positions of other rules claim:
 * blessing Option ∘ Option arithmetic on the strength of the both-absent
 * case alone would certify expressions that crash for every partly-present
 * pair. The agreement harness exempts this rule from the refusal law for
 * exactly that reason — it is deliberately, documentedly modest.
 */
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

    public function handles(string $operator): bool
    {
        return in_array($operator, self::operators, strict: true);
    }

    /**
     * @return Result<Type, TypeMismatch>
     */
    public function typeOf(string $operator, Type $left, Type $right): Result
    {
        return Err(new TypeMismatch('The null rule contributes no static admissibility.'));
    }
}
