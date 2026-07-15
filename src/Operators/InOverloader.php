<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

use Psl\Vec;

use function Superscript\Monads\Result\Err;

/**
 * The mirror of [has]: `needle(s) in haystack` — the right side must be a
 * present list, the left a needle (or list of needles) with absence
 * tolerated, element overlap required. Membership is value equality
 * ({@see ValueEquality}), never PHP's string-comparing array_intersect.
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
            ->map(fn(Type $returns) => new ResolvedOperation($returns, function (mixed $left, array $right): bool {
                // The native array type is the resolution's proof: the right
                // operand type is a present list.
                $haystack = Vec\filter_nulls($right);

                $needles = Vec\filter_nulls(is_array($left) ? $left : [$left]);

                if ($needles === []) {
                    return false;
                }

                return array_all($needles, fn(mixed $needle) => ValueEquality::contains($haystack, $needle));
            }));
    }
}
