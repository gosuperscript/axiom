<?php

declare(strict_types=1);

namespace Superscript\Axiom\Eval;

use Superscript\Axiom\Runtime\AnalyzedProgram;
use Superscript\Axiom\Values\Value;

interface Evaluator
{
    /**
     * @param array<string, mixed> $input
     */
    public function evaluate(AnalyzedProgram $program, string $expressionName, array $input = []): Value;
}
