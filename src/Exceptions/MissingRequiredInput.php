<?php

declare(strict_types=1);

namespace Superscript\Axiom\Exceptions;

/**
 * The call supplied nothing for one or more required inputs the program
 * reads, and everything it did supply was admissible. `$inputs` names the
 * inputs the call is waiting on.
 *
 * This is the benign half of the taxonomy {@see BoundaryViolation} describes:
 * a program whose inputs are not all there yet has no answer, which is not
 * the same as a caller getting an answer wrong.
 */
final class MissingRequiredInput extends BoundaryViolation {}
