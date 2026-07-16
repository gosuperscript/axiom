<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

/** @internal Compiler for the core symbol source. */
final readonly class SymbolSourceCompiler
{
    /** @return Result<CompiledNode, TypeMismatch> */
    public static function compile(SymbolSource $source, SourceCompilation $compilation): Result
    {
        return $compilation->symbol($source);
    }
}
