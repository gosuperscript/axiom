<?php

declare(strict_types=1);

namespace Superscript\Axiom\Exceptions;

use RuntimeException;

/**
 * @internal The straight-line source compiler's private "nothing to judge"
 * channel, raised when a judgment is asked about a child that already failed.
 *
 * It is not a refusal and carries no message: TypeInference turns it into a
 * node typed {@see \Superscript\Axiom\Types\ErrorType}, so the fault below is
 * reported once and this source inherits it silently.
 */
final class CompilationAbsorbed extends RuntimeException {}
