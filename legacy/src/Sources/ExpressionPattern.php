<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Source;

final readonly class ExpressionPattern implements MatchPattern
{
    public function __construct(
        public Source $source,
    ) {}
}
