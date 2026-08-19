<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Monads\Option\Option;

/**
 * The runtime representation of nested option constructors.
 *
 * A compiled node's Result<Option<mixed>> channel carries the outermost
 * constructor. Further constructors are Option values nested inside its
 * Some branch. Most source compilers consume absence as one null, while the
 * structural option operations inspect the layers before collapsing them.
 *
 * @internal
 */
final class OptionLayers
{
    public static function collapse(mixed $value): mixed
    {
        while ($value instanceof Option) {
            $value = $value->unwrapOr(null);
        }

        return $value;
    }

    /** @return Option<mixed> */
    public static function normalize(mixed $value, bool $preserveOptionValue): Option
    {
        return $preserveOptionValue && $value instanceof Option
            ? new \Superscript\Monads\Option\Some($value)
            : Option::from($value);
    }

    /**
     * Read one record property into the compiled node's option channel.
     *
     * @param Option<mixed> $property
     * @return Option<mixed>
     */
    public static function read(Option $property, bool $preserveValueAbsence): Option
    {
        return $preserveValueAbsence
            ? $property->map(static fn(mixed $value): Option => Option::from($value))
            : $property->andThen(static fn(mixed $value): Option => Option::from($value));
    }
}
