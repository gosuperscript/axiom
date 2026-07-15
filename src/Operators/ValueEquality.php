<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

/**
 * The one notion of value equality, shared by the comparison and set
 * operators: numeric comparison when both operands are numbers (1 == 1.0
 * holds — one Number base, matching literal-shape identity and
 * Type::compare), strict identity otherwise, element-wise for arrays, and
 * false across bases (5 never equals '5'; true never equals 1). Never PHP
 * juggling — this is what makes a dead verdict honest by construction.
 */
final class ValueEquality
{
    public static function equals(mixed $left, mixed $right): bool
    {
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return $left == $right;
        }

        if (is_array($left) && is_array($right)) {
            return array_keys($left) === array_keys($right)
                && array_all($left, fn(mixed $value, int|string $key) => self::equals($value, $right[$key]));
        }

        return $left === $right;
    }

    /**
     * Membership by value equality: is the needle equal to any element?
     *
     * @param list<mixed> $haystack
     */
    public static function contains(array $haystack, mixed $needle): bool
    {
        return array_any($haystack, fn(mixed $element) => self::equals($element, $needle));
    }
}
