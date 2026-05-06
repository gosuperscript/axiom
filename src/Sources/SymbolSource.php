<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Describable;
use Superscript\Axiom\Source;

final readonly class SymbolSource implements Source, Describable
{
    public function __construct(
        public string $name,
        public ?string $namespace = null,
    ) {}

    public function describe(): string
    {
        return $this->namespace !== null
            ? sprintf('%s.%s', $this->namespace, $this->name)
            : $this->name;
    }
}
