<?php

namespace Superscript\Abacus\Sources;

use Superscript\Abacus\Source;

final readonly class InfixExpression implements Source
{
    public function __construct(
        public Source $left,
        public string $operator,
        public Source $right,
    ) {
    }
}