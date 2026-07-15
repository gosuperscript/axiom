<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

/**
 * A homogeneous string-keyed map with statically unknown keys. Distinct
 * from a record: "all values are T" and "exactly these named fields" are
 * different claims — Dict is the honest type for data whose keys cannot
 * be enumerated.
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
