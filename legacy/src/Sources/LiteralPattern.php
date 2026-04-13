<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

final readonly class LiteralPattern implements MatchPattern
{
    public function __construct(
        public mixed $value,
    ) {}
}
