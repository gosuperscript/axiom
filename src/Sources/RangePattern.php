<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

final readonly class RangePattern implements MatchPattern
{
    public function __construct(
        public int|float $from,
        public int|float $to,
    ) {}
}
