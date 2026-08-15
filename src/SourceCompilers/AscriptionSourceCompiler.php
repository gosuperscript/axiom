<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\Sources\Ascription;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;

/** @internal Compiler for the core ascription bridge. */
final readonly class AscriptionSourceCompiler
{
    /**
     * An ascription is a checked claim: the inner type must overlap the
     * claimed type, and evaluation verifies membership through assert().
     *
     */
    public static function compile(Ascription $source, SourceCompilation $compilation): CompiledSource
    {
        // overlaps(Unknown, T) always holds, which is the admission this
        // bridge exists to provide.
        $inner = $compilation->child($source->source, 'source');
        $ascribed = $compilation->typeOf($inner);

        $overlap = $compilation->overlaps($ascribed, $source->type);

        if ($overlap->isErr()) {
            $compilation->reject(new TypeMismatch(
                sprintf(
                    'The claim that this is %s is false: the value is %s, and no value inhabits both.',
                    TypeDescriber::describe($source->type),
                    TypeDescriber::describe($ascribed),
                ),
                [$overlap->unwrapErr()],
            ));
        }

        return AdmissionNode::from(
            $inner,
            $source->type,
            convert: static fn(mixed $value) => $source->type->assert($value),
            missing: 'The ascribed value reads as missing, but the claim %s is required; claim %s instead if absence is legal here.',
            label: 'is ' . TypeDescriber::describe($source->type),
        );
    }
}
