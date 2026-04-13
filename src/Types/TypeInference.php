<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

/**
 * Best-effort inference of a {@see Type} from a PHP value.
 *
 * Used by {@see \Superscript\Axiom\Sources\StaticSource} when no explicit
 * {@see Type} is passed to the constructor. Returns an {@see UnresolvedType}
 * only when the shape of the value is genuinely ambiguous (e.g. an empty
 * array where the element type cannot be determined).
 */
final class TypeInference
{
    public static function infer(mixed $value): Type
    {
        return match (true) {
            $value === null => new NullType(),
            is_bool($value) => new BooleanType(),
            is_int($value), is_float($value) => new NumberType(),
            is_string($value) => new StringType(),
            is_array($value) => self::inferArray($value),
            default => new UnresolvedType('cannot infer type of ' . get_debug_type($value)),
        };
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function inferArray(array $value): Type
    {
        if ($value === []) {
            // Empty arrays could be either; pick list with an unresolved element
            // type so anything that would read the element type errors clearly.
            return new ListType(new UnresolvedType('empty array element type'));
        }

        $isList = array_is_list($value);
        $elementType = self::infer($value[array_key_first($value)]);

        return $isList ? new ListType($elementType) : new DictType($elementType);
    }
}
