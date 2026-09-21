<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

use Superscript\Axiom\Operators\ValueEquality;

/**
 * A singleton of a scalar base. Substitutable for its base, never the
 * reverse. Literal identity is value equality — the same definition the
 * runtime matcher and the comparison operators consume (5 and 5.0 denote
 * the same Number; boolean and string identity is strict).
 */
final class LiteralShape extends Shape
{
    public readonly Shape $base;

    public function __construct(
        public readonly bool|int|float|string $value,
    ) {
        $this->base = match (true) {
            is_bool($value) => new BooleanShape(),
            is_string($value) => new StringShape(),
            default => new NumberShape(),
        };
    }

    public function equals(Shape $other): bool
    {
        if (!$other instanceof self || !$this->base->equals($other->base)) {
            return false;
        }

        return ValueEquality::equals($this->value, $other->value);
    }

    /**
     * A hash key consistent with {@see equals()}: equal literals always share
     * a key, so a set of literals can be indexed instead of scanned pairwise.
     * The reverse does not hold — numbers key by their float image, which two
     * distinct large integers can share, and NAN keys like itself while
     * equalling nothing — so sharing a key proves only candidacy: a bucket
     * still decides by equals(). Numbers deliberately key across int and
     * float (5 and 5.0 denote the same Number), and both zeroes key as one
     * (-0.0 equals 0.0 but prints differently).
     */
    public function valueKey(): string
    {
        if (is_bool($this->value)) {
            return $this->value ? 'b:1' : 'b:0';
        }

        if (is_string($this->value)) {
            return 's:' . $this->value;
        }

        $number = (float) $this->value;

        return 'n:' . ($number === 0.0 ? '0' : var_export($number, true));
    }
}
