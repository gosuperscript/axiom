<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\Shapes\DictShape;
use Superscript\Axiom\Types\Shapes\ListShape;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\RecordShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Shapes\UnionShape;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Monads\Result\Result;
use Psl\Vec;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * Shared operand judgments — and the matching evaluations — for the set
 * operators (has, in, intersects).
 */
final class SetOperands
{
    /**
     * The membership evaluation `has` and `in` bind: every needle must be
     * contained in the haystack. Containment is value equality
     * ({@see ValueEquality}) — never PHP's array_intersect, whose string
     * comparison juggles types (true in [1] must be false). No present
     * needles (an absent or empty needle side) is membership in nothing:
     * false.
     */
    public static function allContained(mixed $haystack, mixed $needles): bool
    {
        $present = self::present($needles);

        if ($present === []) {
            return false;
        }

        $haystack = self::present($haystack);

        return array_all($present, fn(mixed $needle) => ValueEquality::contains($haystack, $needle));
    }

    /**
     * The intersection evaluation: do the two sides share any value? Same
     * value equality as membership; two sides with no present values share
     * nothing.
     */
    public static function anyShared(mixed $left, mixed $right): bool
    {
        $haystack = self::present($right);

        return array_any(self::present($left), fn(mixed $needle) => ValueEquality::contains($haystack, $needle));
    }

    /**
     * A side's present elements: a scalar wraps to a one-element list, and
     * absent elements drop out — the judgments tolerate absence, so the
     * evaluations read through it.
     *
     * @return list<mixed>
     */
    private static function present(mixed $side): array
    {
        return Vec\filter_nulls(is_array($side) ? $side : [$side]);
    }

    /**
     * The membership judgment: one side must be a present list, the other a
     * needle (or list of needles) with absence tolerated; element types must
     * overlap, with Never (the empty list literal) vacuously legal.
     *
     * @return Result<Type, TypeMismatch>
     */
    public static function membership(Type $list, Type $needle, string $operator, string $listSide): Result
    {
        $listShape = $list->shape();

        if (!$listShape instanceof ListShape) {
            return Err(new TypeMismatch(sprintf(
                'The %s side of [%s] must be a present list; got %s.',
                $listSide,
                $operator,
                TypeDescriber::describe($list),
            )));
        }

        $needleShape = self::elements($needle);

        if ($needleShape === null) {
            return Err(new TypeMismatch(sprintf(
                'The needle of [%s] must be a scalar or a list; got %s.',
                $operator,
                TypeDescriber::describe($needle),
            )));
        }

        $left = $listSide === 'left' ? $list : $needle;
        $right = $listSide === 'left' ? $needle : $list;
        $support = self::supportsValueEquality($left, $right, $operator);

        if ($support->isErr()) {
            return Err($support->unwrapErr());
        }

        if ($needleShape instanceof NeverShape || $listShape->element instanceof NeverShape) {
            return Ok(new BooleanType());
        }

        return TypeRelations::shapesOverlap($listShape->element, $needleShape)
            ->map(fn() => new BooleanType())
            ->mapErr(fn(TypeMismatch $cause) => new TypeMismatch(
                sprintf('[%s] between %s and %s can never hold.', $operator, TypeDescriber::describe($list), TypeDescriber::describe($needle)),
                [$cause],
                dead: true,
            ));
    }

    /**
     * The element judgment for a side that may be a scalar, a list of
     * scalars, or absent (Option stripped — the evaluation filters nulls).
     * Returns null when the side is not element-shaped: dicts and records
     * (the rules claim only scalars, null, and lists). Opaque and Unknown
     * shapes remain visible so ValueEquality::supports can give the
     * operation-independent totality diagnosis.
     */
    public static function elements(Type $operand): ?Shape
    {
        return self::elementShape($operand->shape());
    }

    /**
     * Universal over union members — one supported branch certifies
     * nothing: every runtime value of the operand must be claimed, so a
     * union is element-shaped only when every member is.
     */
    private static function elementShape(Shape $shape): ?Shape
    {
        if ($shape instanceof OptionShape) {
            $shape = $shape->inner;
        }

        if ($shape instanceof ListShape) {
            $shape = $shape->element;
        }

        if ($shape instanceof UnionShape) {
            $members = [];

            foreach ($shape->members as $member) {
                $judged = self::elementShape($member);

                if ($judged === null) {
                    return null;
                }

                $members[] = $judged;
            }

            return UnionShape::of(...$members);
        }

        if ($shape instanceof DictShape || $shape instanceof RecordShape) {
            return null;
        }

        return $shape;
    }

    /**
     * @return Result<bool, TypeMismatch>
     */
    public static function supportsValueEquality(Type $left, Type $right, string $operator): Result
    {
        return ValueEquality::supports($left, $right)
            ->mapErr(fn(TypeMismatch $cause) => new TypeMismatch(
                sprintf(
                    '[%s] cannot compare %s and %s by value equality.',
                    $operator,
                    TypeDescriber::describe($left),
                    TypeDescriber::describe($right),
                ),
                [$cause],
            ));
    }
}
