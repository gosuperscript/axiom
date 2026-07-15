<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;

/**
 * The mirror of [has]: `needle(s) in haystack` — the right side must be a
 * present list, the left a needle (or list of needles) with absence
 * tolerated, element overlap required. Judgment and evaluation are both
 * {@see SetOperands}, shared with [has], so the mirrors cannot drift.
 */
final readonly class InOverloader implements OperatorOverloader
{
    /** @return Result<ResolvedOperation, TypeMismatch> */
    public function resolve(string $operator, Type $left, Type $right): Result
    {
        if ($operator !== 'in') {
            return Err(new TypeMismatch(sprintf('Membership does not resolve [%s].', $operator), unhandled: true));
        }

        return SetOperands::membership($right, $left, $operator, listSide: 'right')
            ->map(fn(Type $returns) => new ResolvedOperation(
                $returns,
                // The native array type is the resolution's proof: the right
                // operand type is a present list.
                static fn(mixed $left, array $right): bool => SetOperands::allContained(haystack: $right, needles: $left),
            ));
    }
}
