<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Source;

final readonly class MatchExpression implements Source
{
    /** @param list<MatchArm> $arms */
    public function __construct(
        public Source $subject,
        public array $arms,
    ) {}
}
