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
 * How types relate to each other: assignability, equivalence, overlap,
 * and operand admissibility, computed by structural recursion over the
 * shapes the types project.
 *
 * The rules these relations obey (each pinned by a test):
 * - Assignability means every value of the source type is a value of the
 *   target type.
 * - Unknown is assignable only to Unknown, is refused at every operand
 *   slot (convert with Coerce or claim with Ascription first), and
 *   overlaps everything — an unknown value can never be ruled out.
 * - A base type accepts its literals ('shop' fits a String slot) and
 *   unions of them; a literal never accepts its base.
 * - Option<T> holds null or a T. So T fits an Option<T> slot,
 *   Option<Option<T>> collapses to Option<T>, and the null literal
 *   (Option<Never>) fits every option slot.
 * - overlaps asks whether any single value could inhabit both types. It
 *   is symmetric, and weaker than assignability in both directions.
 * - admits refuses a union unless every member fits the slot: a
 *   Number|String operand does not reach a Number slot.
 * - jointlyAdmissible asks whether any single operand type could be
 *   admitted by both slots. It differs from overlaps exactly where one
 *   value belongs to two types: [] inhabits both List and Dict, but no
 *   operand type is admitted by both a List slot and a Dict slot.
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
     * either way. Overlap says only whether a shared value is possible;
     * it does not certify that an operation supports either type.
     *
     * @return Result<bool, TypeMismatch>
     */
    public static function overlaps(Type $a, Type $b): Result
    {
        return self::shapesOverlap($a->shape(), $b->shape());
    }

    /**
     * Could one operand type be admitted by both slots? This is how the
     * Dialect detects ambiguous rows: two rules for the same operator
     * collide exactly when some operand type would resolve both — a
     * 5-typed operand reaches both a Number slot and a Literal(5) slot.
     * Sharing a value is not enough: [] inhabits both List and Dict, but
     * no operand type is admitted by both slots, so a List rule beside a
     * Dict rule can never be in competition.
     *
     * @return Result<bool, TypeMismatch>
     */
    public static function jointlyAdmissible(Type $a, Type $b): Result
    {
        return self::shapesJointlyAdmissible($a->shape(), $b->shape());
    }

    /**
     * May values of $operand reach a rule's $slot? Assignability, named
     * for its use at operand positions. An Unknown operand is always
     * refused, and the error names the two ways forward: convert the value
     * with a Coerce node, or claim its type with an Ascription.
     *
     * @return Result<bool, TypeMismatch>
     */
    public static function admits(Type $operand, Type $slot): Result
    {
        if ($operand->shape() instanceof UnknownShape) {
            return Err(new TypeMismatch(
                'An Unknown operand is inert: claim its type with an Ascription, or convert it with a Coerce, before operating on it.',
            ));
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
        return self::satisfiable($a, $b, dispatch: false);
    }

    /**
     * @return Result<bool, TypeMismatch>
     */
    public static function shapesJointlyAdmissible(Shape $a, Shape $b): Result
    {
        return self::satisfiable($a, $b, dispatch: true);
    }

    /**
     * One recursion, two questions. Overlap ($dispatch = false) asks "could
     * one VALUE satisfy both?"; joint admissibility ($dispatch = true) asks
     * "could one inhabited operand TYPE be admitted by both slots?". The
     * questions share every structural rule and diverge exactly twice:
     * Unknown overlaps everything but admits nothing, and the one-value-
     * two-types corners (the empty array inhabits List, Dict, and the empty
     * Record) overlap without any operand type reaching both slots.
     *
     * @return Result<bool, TypeMismatch>
     */
    private static function satisfiable(Shape $a, Shape $b, bool $dispatch): Result
    {
        if ($a instanceof UnknownShape || $b instanceof UnknownShape) {
            return $dispatch
                ? Err(new TypeMismatch('Unknown is inert: no compilable operand type reaches an Unknown slot.'))
                : Ok(true);
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
            return self::satisfiable($a->inner, $b, $dispatch)
                ->mapErr(fn(TypeMismatch $cause) => self::unsatisfiable($a, $b, $dispatch, [$cause]));
        }

        if ($b instanceof OptionShape) {
            return self::satisfiable($a, $b->inner, $dispatch)
                ->mapErr(fn(TypeMismatch $cause) => self::unsatisfiable($a, $b, $dispatch, [$cause]));
        }

        if ($a instanceof UnionShape || $b instanceof UnionShape) {
            [$union, $other] = $a instanceof UnionShape ? [$a, $b] : [$b, $a];
            $causes = [];

            foreach ($union->members as $member) {
                $result = self::satisfiable($member, $other, $dispatch);

                if ($result->isOk()) {
                    return Ok(true);
                }

                $causes[] = $result->unwrapErr();
            }

            return Err(self::unsatisfiable($a, $b, $dispatch, $causes));
        }

        if ($a instanceof LiteralShape || $b instanceof LiteralShape) {
            [$literal, $other] = $a instanceof LiteralShape ? [$a, $b] : [$b, $a];

            return $literal->base->equals($other) ? Ok(true) : Err(self::unsatisfiable($a, $b, $dispatch));
        }

        if ($a instanceof ListShape && $b instanceof ListShape) {
            return self::listsSatisfiable($a, $b, $dispatch);
        }

        if ($a instanceof DictShape && $b instanceof DictShape) {
            return Ok(true);
        }

        if ($a instanceof RecordShape && $b instanceof RecordShape) {
            return self::recordsSatisfiable($a, $b, $dispatch);
        }

        if ($a instanceof RecordShape && $b instanceof DictShape) {
            return self::recordDictSatisfiable($a, $b, $dispatch);
        }

        if ($a instanceof DictShape && $b instanceof RecordShape) {
            return self::recordDictSatisfiable($b, $a, $dispatch);
        }

        if ($a instanceof OpaqueShape && $b instanceof OpaqueShape) {
            return self::opaquesSatisfiable($a, $b, $dispatch);
        }

        if ($a instanceof ListShape && $b instanceof DictShape) {
            return $dispatch ? Err(self::noCommonOperandType($a, $b)) : self::listOverlapsDict($a, $b);
        }

        if ($a instanceof DictShape && $b instanceof ListShape) {
            return $dispatch ? Err(self::noCommonOperandType($a, $b)) : self::listOverlapsDict($b, $a);
        }

        if ($a instanceof ListShape && $b instanceof RecordShape) {
            return $dispatch ? Err(self::noCommonOperandType($a, $b)) : self::listOverlapsRecord($a, $b);
        }

        if ($a instanceof RecordShape && $b instanceof ListShape) {
            return $dispatch ? Err(self::noCommonOperandType($a, $b)) : self::listOverlapsRecord($b, $a);
        }

        return Err(self::unsatisfiable($a, $b, $dispatch));
    }

    private static function noCommonOperandType(Shape $a, Shape $b): TypeMismatch
    {
        return new TypeMismatch(sprintf(
            '%s and %s admit no common operand type: the empty array is one value with two types, and dispatch sees types, never values.',
            TypeDescriber::describeShape($a),
            TypeDescriber::describeShape($b),
        ));
    }

    /**
     * The same one-value-two-types theorem as listOverlapsDict: the empty
     * record's canonical member is exactly [], so it shares that value with
     * every list that admits emptiness. A record with fields never overlaps
     * a list — its members carry string keys (coercion canonicalizes even
     * optional fields to present keys), and no list value has any.
     *
     * @return Result<bool, TypeMismatch>
     */
    private static function listOverlapsRecord(ListShape $list, RecordShape $record): Result
    {
        if ($record->properties === [] && $list->min === 0) {
            return Ok(true);
        }

        return Err(self::noOverlap($list, $record, [
            new TypeMismatch('Only the empty array inhabits both a list and a record, so they overlap exactly when the record is empty and the list can be empty.'),
        ]));
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
    private static function opaquesSatisfiable(OpaqueShape $a, OpaqueShape $b, bool $dispatch): Result
    {
        if ($a->identity !== $b->identity || array_keys($a->parameters) !== array_keys($b->parameters)) {
            return Err(self::unsatisfiable($a, $b, $dispatch));
        }

        $causes = [];

        foreach ($a->parameters as $name => $parameter) {
            $result = self::satisfiable($parameter, $b->parameters[$name], $dispatch);

            if ($result->isErr()) {
                $causes[] = new TypeMismatch(sprintf("Parameter '%s' cannot satisfy both.", $name), [$result->unwrapErr()]);
            }
        }

        return $causes === [] ? Ok(true) : Err(self::unsatisfiable($a, $b, $dispatch, $causes));
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
        // Records are exact — no width subtyping. A source property the
        // target does not declare makes source values non-members. Presence
        // is covariant in the useful direction: a required source may fill
        // an optional target, but an optional source cannot promise a key a
        // required target needs.
        $causes = [];

        foreach (array_keys($source->properties) as $name) {
            if (!isset($target->properties[$name])) {
                $causes[] = new TypeMismatch(sprintf("Property '%s' is not part of the record.", $name));
            }
        }

        foreach ($target->properties as $name => $property) {
            if (isset($source->properties[$name])) {
                $sourceProperty = $source->properties[$name];

                if (!$property->optional && $sourceProperty->optional) {
                    $causes[] = new TypeMismatch(sprintf("Required property '%s' may be omitted by the source.", $name));
                }

                $result = self::assignable($sourceProperty->value, $property->value);

                if ($result->isErr()) {
                    $causes[] = new TypeMismatch(sprintf("Property '%s' is incompatible.", $name), [$result->unwrapErr()]);
                }
            } elseif (!$property->optional) {
                $causes[] = new TypeMismatch(sprintf("Required property '%s' is missing.", $name));
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

        foreach ($source->properties as $name => $property) {
            if ($property->value instanceof OptionShape) {
                $causes[] = new TypeMismatch(sprintf("Property '%s' may contain an absent value, and dict values are never absent.", $name));

                continue;
            }

            $result = self::assignable($property->value, $target->value);

            if ($result->isErr()) {
                $causes[] = new TypeMismatch(sprintf("Property '%s' is incompatible.", $name), [$result->unwrapErr()]);
            }
        }

        return $causes === [] ? Ok(true) : Err(self::mismatchWith($source, $target, $causes));
    }

    /**
     * When the bound intersection admits emptiness, both questions answer
     * yes for the same reason stated in two vocabularies: the value [] for
     * overlap, the inhabited type List<Never, 0, 0> for dispatch.
     *
     * @return Result<bool, TypeMismatch>
     */
    private static function listsSatisfiable(ListShape $a, ListShape $b, bool $dispatch): Result
    {
        $lower = max($a->min, $b->min);
        $upper = match (true) {
            $a->max === null => $b->max,
            $b->max === null => $a->max,
            default => min($a->max, $b->max),
        };

        if ($upper !== null && $lower > $upper) {
            return Err(self::unsatisfiable($a, $b, $dispatch, [new TypeMismatch('The length bounds do not intersect.')]));
        }

        if ($lower === 0) {
            return Ok(true);
        }

        return self::satisfiable($a->element, $b->element, $dispatch)
            ->mapErr(fn(TypeMismatch $cause) => self::unsatisfiable($a, $b, $dispatch, [$cause]));
    }

    /**
     * @return Result<bool, TypeMismatch>
     */
    private static function recordsSatisfiable(RecordShape $a, RecordShape $b, bool $dispatch): Result
    {
        $causes = [
            ...self::forbiddenRequiredFields($a, $b),
            ...self::forbiddenRequiredFields($b, $a),
        ];

        foreach ($a->properties as $name => $property) {
            if (!isset($b->properties[$name])) {
                continue;
            }

            $other = $b->properties[$name];
            $result = self::satisfiable($property->value, $other->value, $dispatch);

            if ($result->isErr() && !($property->optional && $other->optional)) {
                $causes[] = new TypeMismatch(sprintf("Property '%s' cannot satisfy both records.", $name), [$result->unwrapErr()]);
            }
        }

        return $causes === [] ? Ok(true) : Err(self::unsatisfiable($a, $b, $dispatch, $causes));
    }

    /**
     * @return list<TypeMismatch>
     */
    private static function forbiddenRequiredFields(RecordShape $requiring, RecordShape $other): array
    {
        $causes = [];

        foreach ($requiring->properties as $name => $property) {
            if (!$property->optional && !isset($other->properties[$name])) {
                $causes[] = new TypeMismatch(sprintf("Required property '%s' is forbidden by the record.", $name));
            }
        }

        return $causes;
    }

    /**
     * A record can satisfy a dict in both vocabularies: a record value
     * whose required fields inhabit the dict's value type is a dict member
     * (overlap), and the required-slice record type is assignable to the
     * dict (dispatch) — optional fields drop out either way, since a
     * missing optional key is a legal record member.
     *
     * @return Result<bool, TypeMismatch>
     */
    private static function recordDictSatisfiable(RecordShape $record, DictShape $dict, bool $dispatch): Result
    {
        $causes = [];

        foreach ($record->properties as $name => $property) {
            if ($property->optional) {
                continue;
            }

            $result = self::satisfiable($property->value, $dict->value, $dispatch);

            if ($result->isErr()) {
                $causes[] = new TypeMismatch(sprintf("Required property '%s' cannot inhabit the dict.", $name), [$result->unwrapErr()]);
            }
        }

        return $causes === [] ? Ok(true) : Err(self::unsatisfiable($record, $dict, $dispatch, $causes));
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

    /**
     * @param list<TypeMismatch> $causes
     */
    private static function unsatisfiable(Shape $a, Shape $b, bool $dispatch, array $causes = []): TypeMismatch
    {
        return new TypeMismatch(
            sprintf(
                $dispatch ? '%s and %s admit no common operand type.' : '%s and %s share no values.',
                TypeDescriber::describeShape($a),
                TypeDescriber::describeShape($b),
            ),
            $causes,
        );
    }
}
