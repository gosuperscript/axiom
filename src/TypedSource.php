<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Axiom\Types\TypeEnvironment;
use Superscript\Axiom\Types\TypeInference;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

/**
 * The host-source seam, one statement: a source compiles to its type and
 * its evaluation together ({@see CompiledNode}), so a host cannot register
 * behavior its type claim does not describe — there is no separate place
 * to put it.
 *
 * A source that knows its type declares it beside the lookup that produces
 * a value of it (a geocoding source returns its coordinates record); one
 * that cannot type itself returns Unknown honestly (a raw lookup cell) —
 * and its value must then pass an explicit Coerce or Ascription before
 * anything operates on it; one that wraps another source delegates through
 * the compiler.
 *
 * An unhandled node is a compile *error*, not a silent Unknown — "any
 * expression edge starts here" stays a kept promise.
 */
interface TypedSource extends Source
{
    /**
     * @return Result<CompiledNode, TypeMismatch>
     */
    public function compile(TypeEnvironment $environment, TypeInference $compiler): Result;
}
