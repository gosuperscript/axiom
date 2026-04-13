<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Axiom\Types\Type;

interface Source
{
    /**
     * Return the {@see Type} this source will resolve to, given the
     * declarations available in $context.
     *
     * Implementations must not evaluate the source — they only compute
     * its static output type. When the type cannot be determined (unknown
     * symbol, incompatible operands, etc.) they return a
     * {@see \Superscript\Axiom\Types\UnresolvedType} with an explanatory
     * reason; {@see TypeChecker} turns those into type-check errors.
     */
    public function type(Context $context): Type;
}
