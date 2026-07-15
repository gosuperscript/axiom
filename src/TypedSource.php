<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Axiom\Types\TypeEnvironment;
use Superscript\Axiom\Types\TypeInference;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

/**
 * A host-provided source that can compile itself: compile() returns the
 * source's type and its evaluation together ({@see CompiledNode}), so
 * there is no second registration for behavior to drift away from its
 * type claim.
 *
 * Answering with a CompiledNode is the same promise an operator rule
 * makes: the evaluation's results are values of the stated type, and
 * nothing re-checks them at runtime. A source that knows its type
 * declares it beside the lookup that produces a value of it (a geocoding
 * source returns its coordinates record); one that cannot type itself
 * returns Unknown honestly (a raw lookup cell) — its value must then pass
 * an explicit Coerce or Ascription before anything operates on it; one
 * that wraps another source delegates through the compiler.
 *
 * A Source the compiler has no rule for and that does not implement this
 * interface is a compile error, never a silent Unknown.
 */
interface TypedSource extends Source
{
    /**
     * @return Result<CompiledNode, TypeMismatch>
     */
    public function compile(TypeEnvironment $environment, TypeInference $compiler): Result;
}
