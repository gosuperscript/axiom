<?php

declare(strict_types=1);

namespace Superscript\Axiom;

interface ResolutionInspector
{
    /**
     * Record a piece of metadata about the current resolution.
     * Resolvers call this to expose data (HTTP responses, lookup
     * details, cache hits, etc.) without knowing who is listening.
     */
    public function annotate(string $key, mixed $value): void;
}
