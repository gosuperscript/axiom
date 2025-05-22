<?php

namespace Superscript\Abacus\Sources;

use Superscript\Abacus\Source;

final readonly class UnaryExpression implements Source
{
    public function __construct(
        public string $operator,
        public Source $operand,
    ) {
    }
}