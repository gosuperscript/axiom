<?php

declare(strict_types=1);

namespace Superscript\Axiom;

/**
 * The leniency dial for typed bindings: how declared inputs are admitted
 * at the Expression boundary. Coerce converts (the lenient default —
 * boundaries are exactly where leniency belongs); Assert verifies strict
 * membership for hosts that pre-validate and want refusal over conversion.
 */
enum Boundary
{
    case Coerce;
    case Assert;
}
