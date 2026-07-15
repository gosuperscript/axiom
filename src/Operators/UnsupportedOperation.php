<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\TypeMismatch;

/** The rule owns the operator but rejects these operand types. */
final readonly class UnsupportedOperation implements OperatorResolution
{
    /** @param list<TypeMismatch> $causes */
    public function __construct(
        public string $message,
        public array $causes = [],
    ) {}
}
