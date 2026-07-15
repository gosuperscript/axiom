<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use InvalidArgumentException;
use Superscript\Axiom\Types\Shapes\BooleanShape;
use Superscript\Axiom\Types\Shapes\DictShape;
use Superscript\Axiom\Types\Shapes\ListShape;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\Shapes\NumberShape;
use Superscript\Axiom\Types\Shapes\OpaqueShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\RecordShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Shapes\StringShape;
use Superscript\Axiom\Types\Shapes\UnionShape;
use Superscript\Axiom\Types\Shapes\UnknownShape;

use function Psl\Vec\map;

/**
 * Shape → Type, mechanically: every sealed constructor has a canonical core
 * type. This is what lets shape-driven judgments (member access) hand back
 * a Type for further inference. Sound because shapes are truth claims — a
 * census-verified projection describes real runtime structure, so the
 * canonical type is a faithful stand-in.
 */
final class TypeReifier
{
    public static function reify(Shape $shape): Type
    {
        return match (true) {
            $shape instanceof BooleanShape => new BooleanType(),
            $shape instanceof NumberShape => new NumberType(),
            $shape instanceof StringShape => new StringType(),
            $shape instanceof LiteralShape => new LiteralType($shape->value),
            $shape instanceof OptionShape => new OptionType(self::reify($shape->inner)),
            $shape instanceof UnionShape => new UnionType(...map($shape->members, self::reify(...))),
            $shape instanceof ListShape => new ListType(self::reify($shape->element), $shape->min, $shape->max),
            $shape instanceof DictShape => new DictType(self::reify($shape->value)),
            $shape instanceof RecordShape => self::record($shape),
            $shape instanceof UnknownShape => new UnknownType(),
            $shape instanceof NeverShape => new NeverType(),
            $shape instanceof OpaqueShape => self::opaque($shape),
            default => throw new InvalidArgumentException(sprintf('Unknown shape [%s]; the shape vocabulary is sealed.', get_class($shape))),
        };
    }

    private static function record(RecordShape $shape): RecordType
    {
        $fields = [];

        foreach ($shape->fields as $name => $field) {
            $fields[$name] = self::reify($field);
        }

        return new RecordType($fields);
    }

    private static function opaque(OpaqueShape $shape): OpaqueType
    {
        $parameters = [];

        foreach ($shape->parameters as $name => $parameter) {
            $parameters[$name] = self::reify($parameter);
        }

        return new OpaqueType($shape->identity, $parameters);
    }
}
