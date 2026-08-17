<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\ReferencePath;
use Superscript\Axiom\SourceCompilation;

/** @internal Compiler for the core rooted-reference source. */
final readonly class ReferencePathCompiler
{
    public static function compile(ReferencePath $source, SourceCompilation $compilation): CompiledSource
    {
        return $compilation->reference($source);
    }
}
