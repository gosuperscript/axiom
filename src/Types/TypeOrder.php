<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\NumberShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Shapes\UnionShape;

/**
 * Are < and > meaningful for this type? An axis orthogonal to the
 * assignability family, and static-only: overloaders own how values rank
 * at runtime; shapes say only whether ranking is defined at all.
 *
 * Number is the only ordered core shape. PHP's willingness to rank strings
 * is not a defined order. Option is unordered: null does not rank.
 */
final class TypeOrder
{
    public static function hasDefinedOrder(Type $type): bool
    {
        return self::shapeHasDefinedOrder($type->shape());
    }

    private static function shapeHasDefinedOrder(Shape $shape): bool
    {
        return match (true) {
            $shape instanceof NumberShape => true,
            $shape instanceof LiteralShape => self::shapeHasDefinedOrder($shape->base),
            $shape instanceof UnionShape => array_all($shape->members, self::shapeHasDefinedOrder(...)),
            default => false,
        };
    }
}
