<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

use Superscript\Axiom\Types\HasValueIdentity;
use Superscript\Axiom\Types\Type;

/**
 * A singleton of an identity-bearing base: substitutable wherever its base
 * is, never the reverse. Two literals are the same shape exactly when their
 * bases are and their identity keys are equal, so 1..5 and 1.0..5 of one
 * band type are one literal. The base type is kept beside its shape because
 * only the type can format the value and key it.
 */
final class IdentityLiteralShape extends Shape
{
    public readonly Shape $base;

    public readonly string $key;

    public function __construct(
        public readonly object $value,
        public readonly Type&HasValueIdentity $type,
    ) {
        $this->base = $type->shape();
        $this->key = $type->identityKey($value);
    }

    public function equals(Shape $other): bool
    {
        return $other instanceof self
            && $this->key === $other->key
            && $this->base->equals($other->base);
    }
}
