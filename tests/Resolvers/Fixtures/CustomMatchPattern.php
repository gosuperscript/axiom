<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Resolvers\Fixtures;

use Superscript\Axiom\Sources\MatchPattern;

final readonly class CustomMatchPattern implements MatchPattern
{
    public function __construct(
        public string $needle,
    ) {}
}
