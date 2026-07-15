<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

/**
 * Answers "can this rule handle EVERY value of this shape?" — the check an
 * operator rule runs before resolving an operand type it did not declare
 * slot-by-slot. A rule that resolves a type promises its closure works for
 * all of that type's values, so a rule that only handles some of them must
 * refuse the type instead.
 *
 * The traversal owns the composite shapes and asks the rule's $leaf
 * predicate about the rest:
 * - a union passes only if every member passes (handling one branch says
 *   nothing about the others);
 * - an option passes if its inner shape passes — null itself always
 *   passes, so a rule whose closure rejects null must not route
 *   option-bearing operands through this traversal;
 * - lists, dicts, and records recurse into their elements and fields;
 * - Unknown always fails: its values are unknowable, so no promise can
 *   cover them;
 * - Never passes vacuously: it has no values to handle;
 * - scalars, literals, and opaques go to $leaf.
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
