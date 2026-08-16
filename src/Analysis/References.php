<?php

declare(strict_types=1);

namespace Superscript\Axiom\Analysis;

use Superscript\Axiom\ReferencePath;

/** @internal Structural input paths, in order of first read. */
final class References
{
    /** @var array<string, ReferencePath> */
    private array $paths = [];

    /** @param list<ReferencePath> $paths */
    public function record(array $paths): void
    {
        foreach ($paths as $path) {
            $this->paths[$path->key()] = $path;
        }
    }

    /** @return list<ReferencePath> */
    public function all(): array
    {
        return array_values($this->paths);
    }
}
