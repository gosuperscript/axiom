<?php

declare(strict_types=1);

namespace Superscript\Axiom\Runtime;

use Superscript\Axiom\Values\Value;

interface Engine
{
    public function parse(ProgramBundle $bundle): ParsedProgram;

    public function analyze(ProgramBundle $bundle): AnalyzedProgram;

    public function evaluate(ProgramBundle $bundle, EvaluationRequest $request): Value;
}
