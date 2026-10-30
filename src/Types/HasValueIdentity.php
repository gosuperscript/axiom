<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

/**
 * A type whose values carry identity: two of its values are the same value
 * exactly when their identity keys are equal. The key is the type's whole
 * definition of equality. Core equality compares two of its values by key
 * ({@see \Superscript\Axiom\Operators\Equality}), literal identity
 * ({@see IdentityLiteralType}) admits a value by key, and a comparison
 * between literals with different keys is refuted at compile time, because
 * it can never hold.
 *
 * The key is called only with values the type admits. It must normalise
 * every spelling of one value to one key — a band written 1..5 and one
 * written 1.0..5 key alike when they denote the same band.
 */
interface HasValueIdentity
{
    public function identityKey(mixed $value): string;
}
