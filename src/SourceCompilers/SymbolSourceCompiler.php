<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\Sources\SymbolSource;

/** @internal Compiler for the core symbol source. */
final readonly class SymbolSourceCompiler
{
    public static function compile(SymbolSource $source, SourceCompilation $compilation): CompiledSource
    {
        return $compilation->symbol($source);
    }
}
