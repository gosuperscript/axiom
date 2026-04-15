<?php

declare(strict_types=1);

namespace Superscript\Axiom\Input;

use Superscript\Axiom\Diagnostics\Diagnostic;
use Superscript\Axiom\Runtime\AnalyzedProgram;

interface InputValidator
{
    /**
     * @param array<string, mixed> $input
     * @return list<Diagnostic>
     */
    public function validate(AnalyzedProgram $program, string $expressionName, array $input = []): array;
}
