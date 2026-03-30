<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Source;

final readonly class MatchArm
{
    public function __construct(
        public MatchPattern $pattern,
        public Source $expression,
    ) {}
}
