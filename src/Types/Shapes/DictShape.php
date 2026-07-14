<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

/**
 * A homogeneous string-keyed map with statically unknown keys. Distinct
 * from an open record: "all values are T" and "these named fields plus
 * anything" are different claims.
 */
final class DictShape extends Shape
{
    public function __construct(
        public readonly Shape $value,
    ) {}

    public function equals(Shape $other): bool
    {
        return $other instanceof self && $this->value->equals($other->value);
    }
}
