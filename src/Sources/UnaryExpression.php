<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Source;

final readonly class UnaryExpression implements Source
{
    public function __construct(
        public string $operator,
        public Source $operand,
    ) {}
}
