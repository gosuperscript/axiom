<?php

declare(strict_types=1);

namespace Superscript\Abacus\Sources;

use Superscript\Abacus\Source;

final readonly class SymbolSource implements Source
{
    public function __construct(
        public string $name,
        public ?string $namespace = null,
    ) {}
}
