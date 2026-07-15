<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

/**
 * Defines what "equal" means for engine values, in three rules:
 *
 * - Two numbers (int or float) compare numerically: 1 equals 1.0.
 * - Two arrays compare entry-wise: same keys in the same order, every
 *   value equal by these same rules.
 * - Everything else compares by strict identity: 5 never equals '5',
 *   true never equals 1.
 *
 * PHP's own == juggles types across bases; this never does. Every place
 * the engine compares values — the equality operator, has/in/intersects,
 * literal patterns in match, literal types — calls this class, so no two
 * of them can disagree about what "equal" means.
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
