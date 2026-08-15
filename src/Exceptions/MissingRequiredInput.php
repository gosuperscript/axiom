<?php

declare(strict_types=1);

namespace Superscript\Axiom\Exceptions;

/**
 * The call supplied nothing for one or more required inputs the program
 * reads, and everything it did supply was admissible. `$rejections` holds one
 * entry per input the call is waiting on, each naming that input.
 *
 * This is the benign half of the taxonomy {@see BoundaryViolation} describes:
 * a program whose inputs are not all there yet has no answer, which is not
 * the same as a caller getting an answer wrong.
 */
final class MissingRequiredInput extends BoundaryViolation {}
