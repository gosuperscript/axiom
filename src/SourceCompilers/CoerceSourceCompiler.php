<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\Exceptions\CompilationAborted;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\SourceEvaluation;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Monads\Option\Option;

/** @internal Compiler for the core coercion bridge. */
final readonly class CoerceSourceCompiler
{
    /**
     * The Coerce bridge types verbatim — the boundary is statically opaque
     * by design (coercion is admission policy, not membership).
     *
     */
    public static function compile(Coerce $source, SourceCompilation $compilation): CompiledSource
    {
        // A static value the literal registry cannot type still has a total
        // evaluation, and Coerce discards its inner type. Every other source
        // must compile because it runs.
        try {
            $inner = $compilation->child($source->source);
        } catch (CompilationAborted $aborted) {
            if (!$source->source instanceof StaticSource) {
                throw $aborted;
            }

            $inner = ConstantNode::from($source->source->value);
        }

        return AdmissionNode::from(
            $inner,
            $source->type,
            convert: static fn(mixed $value, SourceEvaluation $evaluation) => $source->type->coerce($value)
                ->inspect(fn(Option $coerced) => $coerced->inspect(function (mixed $coercedValue) use ($value, $evaluation) {
                    if ($coercedValue !== $value) {
                        $evaluation->annotate('coercion', get_debug_type($value) . ' -> ' . get_debug_type($coercedValue));
                    }
                })),
            missing: 'The coerced value reads as missing, but %s is required; coerce to %s instead if absence is legal here.',
            label: TypeDescriber::describe($source->type),
        );
    }
}
