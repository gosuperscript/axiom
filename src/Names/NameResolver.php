<?php

declare(strict_types=1);

namespace Superscript\Axiom\Names;

use Superscript\Axiom\Runtime\ParsedProgram;
use Superscript\Axiom\Runtime\ResolvedProgram;

interface NameResolver
{
    public function resolve(ParsedProgram $program): ResolvedProgram;
}
