<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;

/** A binary rule owns one operator and judges its operand types. */
interface BinaryOperatorRule
{
    public function operator(): string;

    public function resolve(Type $left, Type $right): OperatorResolution;
}
