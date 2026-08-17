<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use InvalidArgumentException;
use Superscript\Axiom\Describable;
use Superscript\Axiom\ReferencePath;
use Superscript\Axiom\Source;

/**
 * @deprecated Use {@see \Superscript\Axiom\ReferencePath}. Kept as a persisted-source migration adapter.
 */
final readonly class SymbolSource implements Source, Describable
{
    public function __construct(
        public string $name,
        public ?string $namespace = null,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('A symbol name cannot be empty.');
        }
    }

    /** The legacy flat identity retained for persisted sources and definitions. */
    public static function key(string $name, ?string $namespace = null): string
    {
        return $namespace !== null ? "{$namespace}.{$name}" : $name;
    }

    /** The structural meaning of the legacy flat spelling. */
    public function reference(): ReferencePath
    {
        $segments = explode('.', self::key($this->name, $this->namespace));

        return new ReferencePath($segments[0], ...array_slice($segments, 1));
    }

    public function describe(): string
    {
        return self::key($this->name, $this->namespace);
    }
}
