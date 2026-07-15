<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Axiom\Types\Shapes\DictShape;
use Superscript\Axiom\Types\Shapes\ListShape;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\Shapes\OpaqueShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\RecordShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Shapes\UnionShape;
use Superscript\Axiom\Types\Shapes\UnknownShape;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * The relation registry: assignability, equivalence, overlap, and operand
 * admissibility, by structural recursion over the sealed shape vocabulary.
 *
 * Laws (pinned by tests):
 * - Assignability is ⊆ over value sets.
 * - Unknown is consistent, not transitive: accepted only by itself under
 *   assignability; it always overlaps; it is always admitted at operand slots.
 * - Refinement widens one way: a base accepts its literals and unions of
 *   them, never the reverse.
 * - Option<T> denotes {null} ∪ T, so T <: Option<T>, Option<Option<T>> ≡
 *   Option<T>, and Option<Never> (the null literal) <: every Option<T>.
 * - overlaps is symmetric and not derivable from assignability.
 * - admits is pessimistic: a union must be wholly assignable; Unknown is the
 *   only sanctioned "cannot rule it out" hole.
 */
final class TypeRelations
{
    /**
     * Can a value of $source flow into a $target slot? ⊆ over value sets.
     *
     * @return Result<bool, TypeMismatch>
     */
    public static function isTypeAssignableTo(Type $source, Type $target): Result
    {
        return self::assignable($source->shape(), $target->shape());
    }

    /**
     * Same type? Derived: assignable both ways.
     *
     * @return Result<bool, TypeMismatch>
     */
    public static function areEquivalent(Type $a, Type $b): Result
    {
        return self::shapesEquivalent($a->shape(), $b->shape());
    }

    /**
     * Could any value satisfy both? Symmetric; weaker than assignability
     * either way. The applicability relation for equality and membership.
     *
     * @return Result<bool, TypeMismatch>
     */
    public static function overlaps(Type $a, Type $b): Result
    {
        return self::shapesOverlap($a->shape(), $b->shape());
    }

    /**
     * May values of $operand reach a rule's $slot? Assignable to the slot,
     * or the operand is top-level Unknown. Nothing else.
     *
     * @return Result<bool, TypeMismatch>
     */
    public static function admits(Type $operand, Type $slot): Result
    {
        if ($operand->shape() instanceof UnknownShape) {
            return Ok(true);
        }

        return self::isTypeAssignableTo($operand, $slot);
    }

    /**
     * @return Result<bool, TypeMismatch>
     */
    public static function shapesEquivalent(Shape $a, Shape $b): Result
    {
        return self::assignable($a, $b)
            ->andThen(fn() => self::assignable($b, $a))
            ->mapErr(fn(TypeMismatch $mismatch) => new TypeMismatch(
                sprintf('%s and %s are not equivalent.', TypeDescriber::describeShape($a), TypeDescriber::describeShape($b)),
                [$mismatch],
            ));
    }

    /**
     * @return Result<bool, TypeMismatch>
     */
    public static function assignable(Shape $source, Shape $target): Result
    {
        if ($source->equals($target)) {
            return Ok(true);
        }

        if ($source instanceof UnknownShape || $target instanceof UnknownShape) {
            return self::mismatch($source, $target, 'Unknown certifies nothing and is accepted only by itself');
        }

        if ($source instanceof NeverShape) {
            return Ok(true);
        }

        if ($source instanceof UnionShape) {
            $causes = [];

            foreach ($source->members as $member) {
                $result = self::assignable($member, $target);

                if ($result->isErr()) {
                    $causes[] = $result->unwrapErr();
                }
            }

            return $causes === []
                ? Ok(true)
                : self::mismatch($source, $target, 'every union member must be assignable', $causes);
        }

        if ($target instanceof OptionShape) {
            $present = $source instanceof OptionShape ? $source->inner : $source;

            return self::assignable($present, $target->inner)
                ->mapErr(fn(TypeMismatch $cause) => self::mismatchWith($source, $target, [$cause]));
        }

        if ($source instanceof OptionShape) {
            return self::mismatch($source, $target, 'the value may be absent and the target does not admit null');
        }

        if ($target instanceof UnionShape) {
            $causes = [];

            foreach ($target->members as $member) {
                $result = self::assignable($source, $member);

                if ($result->isOk()) {
                    return Ok(true);
                }

                $causes[] = $result->unwrapErr();
            }

            return self::mismatch($source, $target, 'no union member accepts it', $causes);
        }

        if ($source instanceof LiteralShape) {
            return $source->base->equals($target)
                ? Ok(true)
                : self::mismatch($source, $target, 'a literal is substitutable only for its own base');
        }

        if ($source instanceof ListShape && $target instanceof ListShape) {
            return self::listAssignable($source, $target);
        }

        if ($source instanceof DictShape && $target instanceof DictShape) {
            return self::assignable($source->value, $target->value)
                ->mapErr(fn(TypeMismatch $cause) => self::mismatchWith($source, $target, [$cause]));
        }

        if ($source instanceof RecordShape && $target instanceof RecordShape) {
            return self::recordAssignable($source, $target);
        }

        if ($source instanceof RecordShape && $target instanceof DictShape) {
            return self::recordAssignableToDict($source, $target);
        }

        if ($source instanceof OpaqueShape && $target instanceof OpaqueShape) {
            return self::opaqueAssignable($source, $target);
        }

        return self::mismatch($source, $target);
    }

    /**
     * Nominal head, structural parameters: same identity required, then
     * parameter-wise assignability (covariant).
     *
     * @return Result<bool, TypeMismatch>
     */
    private static function opaqueAssignable(OpaqueShape $source, OpaqueShape $target): Result
    {
        if ($source->identity !== $target->identity) {
            return self::mismatch($source, $target, 'nominal identities differ');
        }

        if (array_keys($source->parameters) !== array_keys($target->parameters)) {
            return self::mismatch($source, $target, 'the parameter lists differ');
        }

        $causes = [];

        foreach ($target->parameters as $name => $parameter) {
            $result = self::assignable($source->parameters[$name], $parameter);

            if ($result->isErr()) {
                $causes[] = new TypeMismatch(sprintf("Parameter '%s' is incompatible.", $name), [$result->unwrapErr()]);
            }
        }

        return $causes === [] ? Ok(true) : Err(self::mismatchWith($source, $target, $causes));
    }

    /**
     * @return Result<bool, TypeMismatch>
     */
    public static function shapesOverlap(Shape $a, Shape $b): Result
    {
        if ($a instanceof UnknownShape || $b instanceof UnknownShape) {
            return Ok(true);
        }

        if ($a instanceof NeverShape || $b instanceof NeverShape) {
            return Err(new TypeMismatch('Never has no values, so it overlaps nothing.'));
        }

        if ($a->equals($b)) {
            return Ok(true);
        }

        if ($a instanceof OptionShape && $b instanceof OptionShape) {
            return Ok(true);
        }

        if ($a instanceof OptionShape) {
            return self::shapesOverlap($a->inner, $b)
                ->mapErr(fn(TypeMismatch $cause) => self::noOverlap($a, $b, [$cause]));
        }

        if ($b instanceof OptionShape) {
            return self::shapesOverlap($a, $b->inner)
                ->mapErr(fn(TypeMismatch $cause) => self::noOverlap($a, $b, [$cause]));
        }

        if ($a instanceof UnionShape || $b instanceof UnionShape) {
            [$union, $other] = $a instanceof UnionShape ? [$a, $b] : [$b, $a];
            $causes = [];

            foreach ($union->members as $member) {
                $result = self::shapesOverlap($member, $other);

                if ($result->isOk()) {
                    return Ok(true);
                }

                $causes[] = $result->unwrapErr();
            }

            return Err(self::noOverlap($a, $b, $causes));
        }

        if ($a instanceof LiteralShape || $b instanceof LiteralShape) {
            [$literal, $other] = $a instanceof LiteralShape ? [$a, $b] : [$b, $a];

            return $literal->base->equals($other) ? Ok(true) : Err(self::noOverlap($a, $b));
        }

        if ($a instanceof ListShape && $b instanceof ListShape) {
            return self::listsOverlap($a, $b);
        }

        if ($a instanceof DictShape && $b instanceof DictShape) {
            return Ok(true);
        }

        if ($a instanceof RecordShape && $b instanceof RecordShape) {
            return self::recordsOverlap($a, $b);
        }

        if ($a instanceof RecordShape && $b instanceof DictShape) {
            return self::recordOverlapsDict($a, $b);
        }

        if ($a instanceof DictShape && $b instanceof RecordShape) {
            return self::recordOverlapsDict($b, $a);
        }

        if ($a instanceof OpaqueShape && $b instanceof OpaqueShape) {
            return self::opaquesOverlap($a, $b);
        }

        if ($a instanceof ListShape && $b instanceof DictShape) {
            return self::listOverlapsDict($a, $b);
        }

        if ($a instanceof DictShape && $b instanceof ListShape) {
            return self::listOverlapsDict($b, $a);
        }

        return Err(self::noOverlap($a, $b));
    }

    /**
     * The empty array inhabits both List and Dict — one PHP value, two
     * types — so a list that admits emptiness shares exactly that member
     * with every dict, and overlap must say so or a dead verdict would be
     * falsified by [] == []. A list with a positive lower bound shares
     * nothing: dict membership excludes every non-empty list.
     *
     * @return Result<bool, TypeMismatch>
     */
    private static function listOverlapsDict(ListShape $list, DictShape $dict): Result
    {
        if ($list->min === 0) {
            return Ok(true);
        }

        return Err(self::noOverlap($list, $dict, [
            new TypeMismatch('Only the empty array inhabits both a list and a dict, and this list cannot be empty.'),
        ]));
    }

    /**
     * @return Result<bool, TypeMismatch>
     */
    private static function opaquesOverlap(OpaqueShape $a, OpaqueShape $b): Result
    {
        if ($a->identity !== $b->identity || array_keys($a->parameters) !== array_keys($b->parameters)) {
            return Err(self::noOverlap($a, $b));
        }

        $causes = [];

        foreach ($a->parameters as $name => $parameter) {
            $result = self::shapesOverlap($parameter, $b->parameters[$name]);

            if ($result->isErr()) {
                $causes[] = new TypeMismatch(sprintf("Parameter '%s' cannot satisfy both.", $name), [$result->unwrapErr()]);
            }
        }

        return $causes === [] ? Ok(true) : Err(self::noOverlap($a, $b, $causes));
    }

    /**
     * @return Result<bool, TypeMismatch>
     */
    private static function listAssignable(ListShape $source, ListShape $target): Result
    {
        $element = self::assignable($source->element, $target->element);

        if ($element->isErr()) {
            return Err(self::mismatchWith($source, $target, [$element->unwrapErr()]));
        }

        if ($source->min < $target->min || ($target->max !== null && ($source->max === null || $source->max > $target->max))) {
            return self::mismatch($source, $target, 'the length bounds are not contained');
        }

        return Ok(true);
    }

    /**
     * @return Result<bool, TypeMismatch>
     */
    private static function recordAssignable(RecordShape $source, RecordShape $target): Result
    {
        // Records are exact — no width subtyping. A source field the target
        // does not declare makes source values non-members; a missing
        // optional target field is legal absence (coercion canonicalizes it
        // to a present null).
        $causes = [];

        foreach (array_keys($source->fields) as $name) {
            if (!isset($target->fields[$name])) {
                $causes[] = new TypeMismatch(sprintf("Field '%s' is not part of the record.", $name));
            }
        }

        foreach ($target->fields as $name => $field) {
            if (isset($source->fields[$name])) {
                $result = self::assignable($source->fields[$name], $field);

                if ($result->isErr()) {
                    $causes[] = new TypeMismatch(sprintf("Field '%s' is incompatible.", $name), [$result->unwrapErr()]);
                }
            } elseif (!$field instanceof OptionShape) {
                $causes[] = new TypeMismatch(sprintf("Required field '%s' is missing.", $name));
            }
        }

        return $causes === [] ? Ok(true) : Err(self::mismatchWith($source, $target, $causes));
    }

    /**
     * @return Result<bool, TypeMismatch>
     */
    private static function recordAssignableToDict(RecordShape $source, DictShape $target): Result
    {
        $causes = [];

        foreach ($source->fields as $name => $field) {
            if ($field instanceof OptionShape) {
                $causes[] = new TypeMismatch(sprintf("Optional field '%s' may be null, and dict values are never null.", $name));

                continue;
            }

            $result = self::assignable($field, $target->value);

            if ($result->isErr()) {
                $causes[] = new TypeMismatch(sprintf("Field '%s' is incompatible.", $name), [$result->unwrapErr()]);
            }
        }

        return $causes === [] ? Ok(true) : Err(self::mismatchWith($source, $target, $causes));
    }

    /**
     * @return Result<bool, TypeMismatch>
     */
    private static function listsOverlap(ListShape $a, ListShape $b): Result
    {
        $lower = max($a->min, $b->min);
        $upper = match (true) {
            $a->max === null => $b->max,
            $b->max === null => $a->max,
            default => min($a->max, $b->max),
        };

        if ($upper !== null && $lower > $upper) {
            return Err(self::noOverlap($a, $b, [new TypeMismatch('The length bounds do not intersect.')]));
        }

        if ($lower === 0) {
            return Ok(true);
        }

        return self::shapesOverlap($a->element, $b->element)
            ->mapErr(fn(TypeMismatch $cause) => self::noOverlap($a, $b, [$cause]));
    }

    /**
     * @return Result<bool, TypeMismatch>
     */
    private static function recordsOverlap(RecordShape $a, RecordShape $b): Result
    {
        $causes = [
            ...self::forbiddenRequiredFields($a, $b),
            ...self::forbiddenRequiredFields($b, $a),
        ];

        foreach ($a->fields as $name => $field) {
            if (!isset($b->fields[$name])) {
                continue;
            }

            $result = self::shapesOverlap($field, $b->fields[$name]);

            if ($result->isErr()) {
                $causes[] = new TypeMismatch(sprintf("Field '%s' cannot satisfy both records.", $name), [$result->unwrapErr()]);
            }
        }

        return $causes === [] ? Ok(true) : Err(self::noOverlap($a, $b, $causes));
    }

    /**
     * @return list<TypeMismatch>
     */
    private static function forbiddenRequiredFields(RecordShape $requiring, RecordShape $other): array
    {
        $causes = [];

        foreach ($requiring->fields as $name => $field) {
            if (!$field instanceof OptionShape && !isset($other->fields[$name])) {
                $causes[] = new TypeMismatch(sprintf("Required field '%s' is forbidden by the record.", $name));
            }
        }

        return $causes;
    }

    /**
     * @return Result<bool, TypeMismatch>
     */
    private static function recordOverlapsDict(RecordShape $record, DictShape $dict): Result
    {
        $causes = [];

        foreach ($record->fields as $name => $field) {
            if ($field instanceof OptionShape) {
                continue;
            }

            $result = self::shapesOverlap($field, $dict->value);

            if ($result->isErr()) {
                $causes[] = new TypeMismatch(sprintf("Required field '%s' cannot inhabit the dict.", $name), [$result->unwrapErr()]);
            }
        }

        return $causes === [] ? Ok(true) : Err(self::noOverlap($record, $dict, $causes));
    }

    /**
     * @param list<TypeMismatch> $causes
     * @return Result<bool, TypeMismatch>
     */
    private static function mismatch(Shape $source, Shape $target, ?string $reason = null, array $causes = []): Result
    {
        return Err(self::mismatchWith($source, $target, $causes, $reason));
    }

    /**
     * @param list<TypeMismatch> $causes
     */
    private static function mismatchWith(Shape $source, Shape $target, array $causes = [], ?string $reason = null): TypeMismatch
    {
        $message = sprintf(
            '%s is not assignable to %s%s.',
            TypeDescriber::describeShape($source),
            TypeDescriber::describeShape($target),
            $reason === null ? '' : ': ' . $reason,
        );

        return new TypeMismatch($message, $causes);
    }

    /**
     * @param list<TypeMismatch> $causes
     */
    private static function noOverlap(Shape $a, Shape $b, array $causes = []): TypeMismatch
    {
        return new TypeMismatch(
            sprintf('%s and %s share no values.', TypeDescriber::describeShape($a), TypeDescriber::describeShape($b)),
            $causes,
        );
    }
}
