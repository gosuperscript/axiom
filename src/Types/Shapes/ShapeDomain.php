<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

/**
 * The universal-quantification traversal over the sealed algebra, shipped
 * once for every rule that must gate its static verdict on its runtime
 * claim — the totality obligation: Ok(T) certifies EVERY value of the
 * operand types, so a rule whose runtime face is narrower than its verdict
 * refuses the type instead of certifying a crash.
 *
 * The traversal owns the composite constructors — a union is claimed only
 * when every member is (one supported branch certifies nothing), an option
 * adds only null, containers recurse element-wise, Unknown fails (its
 * values are unknowable, so no total claim over them is possible — inert
 * Unknown has no gradual hole here), Never passes vacuously — and delegates
 * every remaining head (scalars, literals, opaques) to the rule's leaf
 * predicate. Option is transparent here, so a rule whose evaluation rejects
 * null must not route option-bearing operands through this traversal.
 */
final class ShapeDomain
{
    /**
     * @param callable(Shape): bool $leaf
     */
    public static function all(Shape $shape, callable $leaf): bool
    {
        if ($shape instanceof UnknownShape) {
            return false;
        }

        if ($shape instanceof NeverShape) {
            return true;
        }

        if ($shape instanceof OptionShape) {
            return self::all($shape->inner, $leaf);
        }

        if ($shape instanceof UnionShape) {
            return array_all($shape->members, fn(Shape $member) => self::all($member, $leaf));
        }

        if ($shape instanceof ListShape) {
            return self::all($shape->element, $leaf);
        }

        if ($shape instanceof DictShape) {
            return self::all($shape->value, $leaf);
        }

        if ($shape instanceof RecordShape) {
            return array_all($shape->fields, fn(Shape $field) => self::all($field, $leaf));
        }

        return $leaf($shape);
    }
}
