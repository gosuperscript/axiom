<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl;

use Superscript\Axiom\Source;

final readonly class CompilationResult
{
    /**
     * @param array<string, Source> $symbols
     * @param array<string, string> $inputs
     * @param list<string> $outputs
     * @param list<Source> $assertions
     */
    public function __construct(
        public array $symbols = [],
        public array $inputs = [],
        public array $outputs = [],
        public array $assertions = [],
    ) {}
}
