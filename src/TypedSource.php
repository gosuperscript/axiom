<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Axiom\Types\TypeEnvironment;
use Superscript\Axiom\Types\TypeInference;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

/**
 * The typing seam for host sources. A source that knows its type declares
 * it (a geocoding source returns its coordinates record); one that cannot
 * returns Unknown honestly (a raw lookup cell); one that wraps another
 * source delegates through the inference.
 *
 * An unhandled node is an inference *error*, not a silent Unknown — "any
 * expression edge starts here" stays a kept promise.
 */
interface TypedSource extends Source
{
    /**
     * @return Result<Types\Type, TypeMismatch>
     */
    public function returnType(TypeEnvironment $environment, TypeInference $inference): Result;
}
