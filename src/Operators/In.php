<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;

/** Membership with the list on the right: `needle(s) in haystack`. */
final readonly class In implements BinaryOperatorRule
{
    public function operator(): string
    {
        return 'in';
    }

    public function resolve(Type $left, Type $right): OperatorResolution
    {
        $judgment = SetOperands::membership($right, $left, $this->operator(), listSide: 'right');

        if ($judgment->isErr()) {
            return self::refusal($judgment->unwrapErr());
        }

        return new ResolvedOperation(
            $judgment->unwrap(),
            static fn(mixed $left, array $right): bool => SetOperands::allContained(haystack: $right, needles: $left),
        );
    }

    private static function refusal(TypeMismatch $mismatch): OperatorResolution
    {
        return $mismatch->dead
            ? new DeadOperation($mismatch->message, $mismatch->causes)
            : new UnsupportedOperation($mismatch->message, $mismatch->causes);
    }
}
