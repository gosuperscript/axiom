<?php

declare(strict_types=1);

namespace Superscript\Axiom\Analysis;

use Superscript\Axiom\Types\Shapes\BooleanShape;
use Superscript\Axiom\Types\Shapes\DictShape;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\ListShape;
use Superscript\Axiom\Types\Shapes\NumberShape;
use Superscript\Axiom\Types\Shapes\OpaqueShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\RecordShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Shapes\StringShape;
use Superscript\Axiom\Types\Shapes\UnionShape;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeReifier;

/** @internal Analysis-safe rendering of types with opt-in literal disclosure. */
final readonly class AnalysisTypeDescriber
{
    public static function describe(Type $type, bool $revealLiterals): string
    {
        if ($revealLiterals) {
            return TypeDescriber::describe($type);
        }

        return TypeDescriber::describe(TypeReifier::reify(self::redact($type->shape())));
    }

    private static function redact(Shape $shape): Shape
    {
        return match (true) {
            $shape instanceof LiteralShape && is_bool($shape->value) => new BooleanShape(),
            $shape instanceof LiteralShape && (is_int($shape->value) || is_float($shape->value)) => new NumberShape(),
            $shape instanceof LiteralShape && is_string($shape->value) => new StringShape(),
            $shape instanceof OptionShape => new OptionShape(self::redact($shape->inner)),
            $shape instanceof UnionShape => UnionShape::of(...array_map(self::redact(...), $shape->members)),
            $shape instanceof ListShape => new ListShape(self::redact($shape->element), $shape->min, $shape->max),
            $shape instanceof DictShape => new DictShape(self::redact($shape->value)),
            $shape instanceof RecordShape => new RecordShape(array_map(self::redact(...), $shape->fields)),
            $shape instanceof OpaqueShape => new OpaqueShape($shape->identity, array_map(self::redact(...), $shape->parameters)),
            default => $shape,
        };
    }
}
