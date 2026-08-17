<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use InvalidArgumentException;
use Superscript\Axiom\Describable;
use Superscript\Axiom\Source;

/**
 * @deprecated Use {@see \Superscript\Axiom\ReferencePath}. Kept as a persisted-source migration adapter.
 */
final readonly class SymbolSource implements Source, Describable
{
    public function __construct(
        public string $name,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('A symbol name cannot be empty.');
        }

        if (str_contains($name, '.')) {
            throw new InvalidArgumentException('A symbol name cannot contain a dot. Use MemberAccessSource for structural access.');
        }
    }

    public function describe(): string
    {
        return $this->name;
    }
}
