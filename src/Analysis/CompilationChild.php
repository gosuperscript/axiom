<?php

declare(strict_types=1);

namespace Superscript\Axiom\Analysis;

/** A compiled dependency edge, optionally named by its source compiler. */
final readonly class CompilationChild
{
    public function __construct(
        public CompilationNode $node,
        public ?string $role = null,
    ) {}
}
