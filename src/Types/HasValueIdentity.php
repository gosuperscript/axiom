<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

/**
 * A type whose values carry identity: two of its values are the same value
 * exactly when their identity keys are equal. Literal identity
 * ({@see IdentityLiteralType}) reads this key, and an equality rule the
 * owning package declares over the type
 * ({@see \Superscript\Axiom\Operators\InfixOperatorRule}) is refuted
 * at compile time when its operand types share no key. The package's
 * equality evaluation must therefore agree with the key: values it calls
 * equal key alike, and values that key alike it calls equal.
 *
 * The key is called only with values the type admits. It must normalise
 * every spelling of one value to one key — a band written 1..5 and one
 * written 1.0..5 key alike when they denote the same band.
 */
interface HasValueIdentity
{
    public function identityKey(mixed $value): string;
}
