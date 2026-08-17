<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use InvalidArgumentException;
use ReflectionClass;
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
 * The single authority for rendering a type in diagnostics.
 */
final class TypeDescriber
{
    /** @param class-string<Type> $type */
    public static function describeClass(string $type): string
    {
        return (new ReflectionClass($type))->getShortName();
    }

    public static function describe(Type $type): string
    {
        return self::describeShape($type->shape());
    }

    public static function describeShape(Shape $shape): string
    {
        return match (true) {
            $shape instanceof BooleanShape => 'Boolean',
            $shape instanceof NumberShape => 'Number',
            $shape instanceof StringShape => 'String',
            $shape instanceof UnknownShape => 'Unknown',
            $shape instanceof NeverShape => 'Never',
            $shape instanceof OpaqueShape => self::opaque($shape),
            $shape instanceof LiteralShape => self::literal($shape->value),
            $shape instanceof OptionShape => self::option($shape),
            $shape instanceof UnionShape => implode(' | ', map($shape->members, self::describeShape(...))),
            $shape instanceof ListShape => self::list($shape),
            $shape instanceof DictShape => sprintf('Dict<%s>', self::describeShape($shape->value)),
            $shape instanceof RecordShape => self::record($shape),
            default => throw new InvalidArgumentException(sprintf('Unknown shape [%s]; the shape vocabulary is sealed.', get_class($shape))),
        };
    }

    private static function literal(bool|int|float|string $value): string
    {
        if (is_string($value)) {
            return sprintf("'%s'", $value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private static function option(OptionShape $shape): string
    {
        $inner = self::describeShape($shape->inner);

        return $shape->inner instanceof UnionShape ? sprintf('(%s)?', $inner) : sprintf('%s?', $inner);
    }

    private static function list(ListShape $shape): string
    {
        $element = self::describeShape($shape->element);

        return match (true) {
            $shape->min === 0 && $shape->max === null => sprintf('List<%s>', $element),
            $shape->min === $shape->max => sprintf('List<%s, %d>', $element, $shape->min),
            $shape->max === null => sprintf('List<%s, %d..>', $element, $shape->min),
            default => sprintf('List<%s, %d..%d>', $element, $shape->min, $shape->max),
        };
    }

    private static function opaque(OpaqueShape $shape): string
    {
        if ($shape->parameters === []) {
            return $shape->identity;
        }

        $parameters = [];

        foreach ($shape->parameters as $name => $parameter) {
            $parameters[] = sprintf('%s: %s', $name, self::describeShape($parameter));
        }

        return sprintf('%s<%s>', $shape->identity, implode(', ', $parameters));
    }

    private static function record(RecordShape $shape): string
    {
        $properties = [];

        foreach ($shape->properties as $name => $property) {
            $properties[] = sprintf(
                '%s: %s%s',
                $name,
                $property->optional ? 'Optional<' : '',
                self::describeShape($property->value) . ($property->optional ? '>' : ''),
            );
        }

        return sprintf('{%s}', implode(', ', $properties));
    }
}
