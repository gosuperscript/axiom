<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;

/** Membership with the list on the left: `haystack has needle(s)`. */
final readonly class Has implements BinaryOperatorRule
{
    public function operator(): string
    {
        return 'has';
    }

    public function resolve(Type $left, Type $right): OperatorResolution
    {
        $judgment = SetOperands::membership($left, $right, $this->operator(), listSide: 'left');

        if ($judgment->isErr()) {
            return self::refusal($judgment->unwrapErr());
        }

        return new ResolvedOperation(
            $judgment->unwrap(),
            static fn(array $left, mixed $right): bool => SetOperands::allContained(haystack: $left, needles: $right),
        );
    }

    private static function refusal(TypeMismatch $mismatch): OperatorResolution
    {
        return $mismatch->dead
            ? new DeadOperation($mismatch->message, $mismatch->causes)
            : new UnsupportedOperation($mismatch->message, $mismatch->causes);
    }
}
