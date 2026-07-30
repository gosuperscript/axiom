<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Axiom\Types\Shapes\OptionShape;

/**
 * The type an optional value exposes when it is present; a type that is
 * already present answers itself. Union-shaped optionality counts: any type
 * whose canonical shape is an option projects to its present member, not
 * only OptionType itself.
 */
final class PresentType
{
    public static function of(Type $type): Type
    {
        if ($type instanceof OptionType) {
            return $type->inner;
        }

        $shape = $type->shape();

        return $shape instanceof OptionShape ? TypeReifier::reify($shape->inner) : $type;
    }
}
