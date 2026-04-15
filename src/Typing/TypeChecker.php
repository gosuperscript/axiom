<?php

declare(strict_types=1);

namespace Superscript\Axiom\Typing;

use Superscript\Axiom\Runtime\AnalyzedProgram;
use Superscript\Axiom\Runtime\ResolvedProgram;

interface TypeChecker
{
    public function analyze(ResolvedProgram $program): AnalyzedProgram;
}
