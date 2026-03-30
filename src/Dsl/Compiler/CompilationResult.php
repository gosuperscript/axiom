<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Compiler;

use Superscript\Axiom\SymbolRegistry;

final readonly class CompilationResult
{
    /**
     * @param list<string> $outputs Public symbol names
     */
    public function __construct(
        public SymbolRegistry $symbols,
        public array $outputs,
    ) {}
}
