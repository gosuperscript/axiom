<?php

declare(strict_types=1);

namespace Superscript\Axiom;

/**
 * The leniency dial for typed bindings: how the inputs a program reads are
 * admitted at its boundary. Coerce converts (the lenient default —
 * boundaries are exactly where leniency belongs); Assert verifies strict
 * membership for hosts that pre-validate and want refusal over conversion.
 *
 * The dial governs conversion only. Whether a property may be omitted is
 * declared with {@see Types\Optional}, and both policies enforce it alike.
 */
enum Boundary
{
    case Coerce;
    case Assert;
}
