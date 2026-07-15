<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\TypeMismatch;

/** A valid operation whose result is statically constant or meaningless. */
final readonly class DeadOperation implements OperatorResolution
{
    /** @param list<TypeMismatch> $causes */
    public function __construct(
        public string $message,
        public array $causes = [],
    ) {}
}
