<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

/** An operator rule with a stable identity suitable for compilation analysis. */
interface IdentifiedOperatorRule
{
    public function identifier(): string;
}
