<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use InvalidArgumentException;
use Superscript\Axiom\Types\Shapes\BooleanShape;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\NumberShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Shapes\ShapeDomain;
use Superscript\Axiom\Types\Shapes\StringShape;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * Defines what "equal" means for engine values, in three rules:
 *
 * - Two numbers (int or float) compare numerically: 1 equals 1.0.
 * - Two arrays compare entry-wise: same keys in the same order, every
 *   value equal by these same rules.
 * - Strings, booleans, and null compare by strict identity: 5 never
 *   equals '5', and true never equals 1.
 *
 * PHP's own == juggles types across bases; this never does. Every place
 * the engine compares values — the equality operator, has/in/intersects,
 * literal patterns in match, literal types — calls this class, so no two
 * of them can disagree about what "equal" means. Object equality is not
 * guessed here: the package that owns an opaque type contributes its own
 * operator rule and evaluation.
 */
final class ValueEquality
{
    /**
     * Is this evaluator total over every possible pair of values from the
     * two operand types? This is deliberately independent of overlap:
     * Number and String are supported but disjoint, while Unknown and an
     * opaque Money type may overlap another type without being supported.
     *
     * @return Result<bool, TypeMismatch>
     */
    public static function supports(Type $left, Type $right): Result
    {
        $causes = [];

        foreach (['left' => $left, 'right' => $right] as $side => $operand) {
            if (ShapeDomain::all($operand->shape(), self::supportsLeaf(...))) {
                continue;
            }

            $causes[] = new TypeMismatch(sprintf(
                'The %s operand %s contains Unknown or opaque values; claim or coerce Unknown first, and let the package that owns an opaque type define its equality.',
                $side,
                TypeDescriber::describe($operand),
            ));
        }

        if ($causes !== []) {
            return Err(new TypeMismatch(sprintf(
                'Value equality is not defined for %s and %s.',
                TypeDescriber::describe($left),
                TypeDescriber::describe($right),
            ), $causes));
        }

        return Ok(true);
    }

    public static function equals(mixed $left, mixed $right): bool
    {
        self::assertSupportedValue($left);
        self::assertSupportedValue($right);

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

    private static function supportsLeaf(Shape $shape): bool
    {
        return $shape instanceof BooleanShape
            || $shape instanceof NumberShape
            || $shape instanceof StringShape
            || $shape instanceof LiteralShape;
    }

    private static function assertSupportedValue(mixed $value): void
    {
        // This is a constant-time guard at each value the comparison
        // already visits, not a recursive comparability preflight. The
        // compiler established support from the operand types.
        if ($value === null || is_scalar($value) || is_array($value)) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Value equality is defined only for null, scalar, and array values; got %s.',
            get_debug_type($value),
        ));
    }
}
