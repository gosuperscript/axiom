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

    public function children(): iterable
    {
        return [];
    }

    /**
     * The flat dotted key a namespaced symbol occupies — the one naming
     * convention every symbol lookup (bindings, definitions, the definition
     * graph, the type environment) answers to. A namespace is a naming
     * convention, not a view into structure, so the key is the whole story.
     */
    public static function key(string $name, ?string $namespace): string
    {
        return $namespace !== null ? "{$namespace}.{$name}" : $name;
    }

    public function describe(): string
    {
        return self::key($this->name, $this->namespace);
    }
}
