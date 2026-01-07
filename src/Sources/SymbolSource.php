<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Source;

final readonly class SymbolSource implements Source
{
    public function __construct(
        public string $name,
        public ?string $namespace = null,
    ) {}
}
