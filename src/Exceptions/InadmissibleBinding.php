<?php

declare(strict_types=1);

namespace Superscript\Axiom\Exceptions;

/**
 * A value was supplied for an input the program reads and does not inhabit
 * that input's declared type: it failed conversion under
 * {@see \Superscript\Axiom\Boundary::Coerce}, failed membership under
 * {@see \Superscript\Axiom\Boundary::Assert}, or read as absent where the
 * declaration requires presence.
 *
 * This is the fault half of the taxonomy {@see BoundaryViolation} describes,
 * and it is what the call reports whenever any supplied value is wrong —
 * required inputs the same call left out are listed in `$violations` beside
 * it, but they do not soften the verdict.
 */
final class InadmissibleBinding extends BoundaryViolation {}
