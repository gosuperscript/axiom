<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;

/** Final stage of a computed rule's successful verdict. */
final readonly class ResolvedOperationBuilder
{
    public function __construct(private Type $returns) {}

    public function evaluatesWith(callable $evaluation): ResolvedOperation
    {
        return new ResolvedOperation($this->returns, $evaluation(...));
    }
}
