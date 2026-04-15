<?php

declare(strict_types=1);

namespace Superscript\Axiom\Runtime;

use Superscript\Axiom\Ast\Program;
use Superscript\Axiom\Diagnostics\Diagnostic;

/**
 * @param list<Diagnostic> $diagnostics
 */
final readonly class AnalyzedProgram
{
    /**
     * @param list<Diagnostic> $diagnostics
     */
    public function __construct(
        public Program $program,
        public array $diagnostics = [],
    ) {}
}
