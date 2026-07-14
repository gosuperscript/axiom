<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

/**
 * Pure nominal: equal only to a shape with the same identity. For domain
 * types that should not be structurally transparent.
 */
final class OpaqueShape extends Shape
{
    public function __construct(
        public readonly string $identity,
    ) {}

    public function equals(Shape $other): bool
    {
        return $other instanceof self && $this->identity === $other->identity;
    }
}
