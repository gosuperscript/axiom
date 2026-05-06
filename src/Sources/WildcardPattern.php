<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Describable;

final readonly class WildcardPattern implements MatchPattern, Describable
{
    public function describe(): string
    {
        return '_';
    }
}
