<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Axiom\Types\Shapes\OptionShape;

/**
 * The type an optional value exposes when it is present; a type that is
 * already present answers itself. Union-shaped optionality counts: any type
 * whose canonical shape is an option projects to its present member, not
 * only OptionType itself.
 *
 * The answer is always present, however many Option constructors a type
 * carries. Constructors remain distinct in the shape algebra, but this
 * projection is deliberately the innermost present type used by strict
 * operations and authored defaults. Peeling only one constructor would
 * leave those operations with an optional operand rather than the value
 * they operate on.
 *
 * Peeling is preferred to reifying wherever a constructor is there to peel:
 * a host's own `Type` survives (an `Option<Money>` exposes the host's
 * `MoneyType`, not the canonical stand-in for its shape), and only
 * optionality that exists solely in the shape is reified.
 */
final class PresentType
{
    public static function of(Type $type): Type
    {
        while (($shape = $type->shape()) instanceof OptionShape) {
            $type = $type instanceof OptionType
                ? $type->inner
                : TypeReifier::reify($shape->inner);
        }

        return $type;
    }
}
