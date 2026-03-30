<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl;

use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Patterns\PatternMatcher;

interface DslPlugin
{
    public function operators(OperatorRegistry $operators): void;

    public function types(TypeRegistry $types): void;

    public function functions(FunctionRegistry $functions): void;

    /** @return list<PatternMatcher> */
    public function patterns(): array;

    /** @return list<DslLiteralExtension> */
    public function literals(): array;

    /** @return list<OperatorOverloader> */
    public function overloaders(): array;
}
