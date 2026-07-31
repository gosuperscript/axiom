<?php

declare(strict_types=1);

namespace Superscript\Axiom\Fields;

use InvalidArgumentException;

/**
 * The front door for declaring a computed field on an opaque type:
 *
 *   Field::on('address')
 *       ->named('postcode')
 *       ->returns(new StringType())
 *       ->extractedWith(fn (Address $a) => $a->postcode)
 *
 * A field is the sanctioned way an opaque type exposes a value: the error
 * "nominal types make no structural claims" stands for every opaque, and a
 * declared field is the one exception — `address.postcode` is certified by
 * this explicit declaration, not by any structural claim about the shape.
 * Any extension may declare a field on any identity — the declarer answers
 * for the extractor being total over that identity's values, and an exact
 * `identity.name` collision between two extensions is refused at composition.
 * The declaration is consulted only at the member-access checkpoint,
 * so it never enters assignability: an address with a declared `postcode` is
 * still not assignable to a `{postcode: String}` record slot, which is what
 * keeps the opaque nominal and its accesses crash-free.
 */
final readonly class Field
{
    public static function on(string $identity): FieldBuilder
    {
        if ($identity === '') {
            throw new InvalidArgumentException('A field must belong to a non-empty opaque identity.');
        }

        return new FieldBuilder($identity);
    }
}
