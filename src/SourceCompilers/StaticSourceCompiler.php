<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\Sources\StaticSource;

/** @internal Compiler for the core static-value source. */
final readonly class StaticSourceCompiler
{
    public static function compile(StaticSource $source, SourceCompilation $compilation): CompiledSource
    {
        return ConstantNode::from($source->value, $compilation->typeOfValue($source->value));
    }
}
