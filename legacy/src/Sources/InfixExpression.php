<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Source;

final readonly class InfixExpression implements Source
{
    public function __construct(
        public Source $left,
        public string $operator,
        public Source $right,
    ) {}
}
