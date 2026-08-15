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
 *
 * ## The two internal control-flow exceptions
 *
 * Compilation raises exactly two exceptions, and this is one of them:
 *
 *  - {@see CompilationAborted} — this source refuses, and says why.
 *  - `CompilationAbsorbed` — this source gives up without refusing, because a
 *    child of it already failed and was already reported.
 *
 * They are exceptions because a source compiler sits at the top of a deep
 * call tree it does not control: a judgment made several helpers down has to
 * unwind to the walk, and a return-based outcome would make every compiler in
 * between — including host compilers Axiom never sees — thread and re-raise it
 * by hand. One forgotten thread is a certified type over an unchecked
 * subtree, which is the failure both channels exist to prevent.
 *
 * Neither escapes {@see \Superscript\Axiom\Types\TypeInference::compile()},
 * which turns both into ordinary values: an abort becomes an `Err` carrying
 * the {@see \Superscript\Axiom\Types\TypeMismatch}, an absorb becomes a node
 * typed ErrorType. What a caller of {@see \Superscript\Axiom\Expression} sees
 * is a `Result`.
 */
final class CompilationAbsorbed extends RuntimeException {}
