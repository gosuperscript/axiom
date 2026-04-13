<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Source;

final readonly class StaticSource implements Source
{
    public function __construct(
        public mixed $value,
    ) {}
}
