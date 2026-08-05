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
 * happens to be built from. Nesting collapses in the shape algebra but not
 * in the types themselves — member access on an optional owner wraps an
 * already-optional field, so `q.premium` on an
 * `Option<{premium: Option<Number>}>` is the type `Option<Option<Number>>`,
 * which describes as `Number?` and denotes {null} ∪ Number. Peeling one
 * constructor off that would answer `Option<Number>` — still optional, so
 * an operator asking what it gets when the value is present would be told
 * "possibly nothing", and `q.premium ?? 0` would certify an optional result
 * for a value that can never be absent.
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
