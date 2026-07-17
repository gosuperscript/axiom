<?php

declare(strict_types=1);

namespace Superscript\Axiom\Exceptions;

use RuntimeException;
use Superscript\Axiom\Types\TypeMismatch;

/** @internal The straight-line source compiler's private failure channel. */
final class CompilationAborted extends RuntimeException
{
    public function __construct(public readonly TypeMismatch $mismatch)
    {
        parent::__construct($mismatch->message);
    }
}
