<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

/** @internal Compiler for the core static-value source. */
final readonly class StaticSourceCompiler
{
    /** @return Result<CompiledNode, TypeMismatch> */
    public static function compile(StaticSource $source, SourceCompilation $compilation): Result
    {
        return $compilation->typeOfValue($source->value)
            ->map(fn(Type $type) => ConstantNode::from($source->value, $type));
    }
}
