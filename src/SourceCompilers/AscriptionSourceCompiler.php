<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\Sources\Ascription;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Monads\Result\Result;

/** @internal Compiler for the core ascription bridge. */
final readonly class AscriptionSourceCompiler
{
    /**
     * An ascription is a checked claim: the inner type must overlap the
     * claimed type, and evaluation verifies membership through assert().
     *
     * @return Result<CompiledNode, TypeMismatch>
     */
    public static function compile(Ascription $source, SourceCompilation $compilation): Result
    {
        // overlaps(Unknown, T) always holds, which is the admission this
        // bridge exists to provide.
        return $compilation->compile($source->source)->andThen(
            fn(CompiledNode $inner) => TypeRelations::overlaps($inner->returns, $source->type)
                ->mapErr(fn(TypeMismatch $cause) => new TypeMismatch(
                    sprintf(
                        'The claim that this is %s is false: the value is %s, and no value inhabits both.',
                        TypeDescriber::describe($source->type),
                        TypeDescriber::describe($inner->returns),
                    ),
                    [$cause],
                ))
                ->map(fn() => AdmissionNode::from(
                    $inner,
                    $source->type,
                    convert: static fn(mixed $value) => $source->type->assert($value),
                    missing: 'The ascribed value reads as missing, but the claim %s is required; claim %s instead if absence is legal here.',
                    label: 'is ' . TypeDescriber::describe($source->type),
                )),
        );
    }
}
