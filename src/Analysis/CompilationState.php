<?php

declare(strict_types=1);

namespace Superscript\Axiom\Analysis;

/**
 * What became of one source in one compilation. It is the whole of what
 * "did this compile?" means in this library: failure is a state a
 * compilation is in, never a type a value could wear, so there is no marker
 * to obtain, wrap, or claim back.
 *
 * The three states are not degrees of the same thing. Certified and Failed
 * are the two outcomes of compiling a source; Abandoned is not an outcome at
 * all, but a position kept so paths stay stable.
 */
enum CompilationState
{
    /** The compiler typed this source: it has a return type and an owning compiler. */
    case Certified;

    /**
     * The compiler gave up on this source — it refused, or a child of it did
     * and it absorbed the failure. It has no return type and no evaluation,
     * and no {@see \Superscript\Axiom\Program} is certified from a tree
     * containing one.
     */
    case Failed;

    /**
     * A position a compiler declined to fill: the child refused and the
     * parent caught the refusal and compiled without it. Nothing was built
     * there, so it claims neither a type nor a compiler — and it is not a
     * failure, because the program that runs does not contain it.
     */
    case Abandoned;
}
