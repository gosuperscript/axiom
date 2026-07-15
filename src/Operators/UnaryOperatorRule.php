<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;

/** A unary rule owns one operator and judges its operand type. */
interface UnaryOperatorRule
{
    public function operator(): string;

    public function resolve(Type $operand): OperatorResolution;
}
