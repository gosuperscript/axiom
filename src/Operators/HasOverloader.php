<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;

/**
 * Membership, list side left: `haystack has needle(s)`. A type function —
 * the left side must be a present list, the right a needle (or list of
 * needles) with absence tolerated, and the element types must overlap
 * (membership that can never hold is dead code). Judgment and evaluation
 * are both {@see SetOperands}, shared with [in], its mirror.
 */
final readonly class HasOverloader implements OperatorOverloader
{
    /** @return Result<ResolvedOperation, TypeMismatch> */
    public function resolve(string $operator, Type $left, Type $right): Result
    {
        if ($operator !== 'has') {
            return Err(new TypeMismatch(sprintf('Membership does not resolve [%s].', $operator), unhandled: true));
        }

        return SetOperands::membership($left, $right, $operator, listSide: 'left')
            ->map(fn(Type $returns) => new ResolvedOperation(
                $returns,
                // The native array type is the resolution's proof: the left
                // operand type is a present list.
                static fn(array $left, mixed $right): bool => SetOperands::allContained(haystack: $left, needles: $right),
            ));
    }
}
