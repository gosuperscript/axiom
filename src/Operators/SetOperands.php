<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\Shapes\DictShape;
use Superscript\Axiom\Types\Shapes\ListShape;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\Shapes\OpaqueShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\RecordShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Shapes\UnionShape;
use Superscript\Axiom\Types\Shapes\UnknownShape;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * Shared operand judgments for the set operators (has, in, intersects).
 */
final class SetOperands
{
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
     * (the rules claim only scalars, null, and lists), opaques (objects —
     * never claimed; membership over a domain type belongs to the rule
     * that owns it), and Unknown (inert — bridge it first).
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

        if ($shape instanceof DictShape || $shape instanceof RecordShape || $shape instanceof OpaqueShape || $shape instanceof UnknownShape) {
            return null;
        }

        return $shape;
    }
}
