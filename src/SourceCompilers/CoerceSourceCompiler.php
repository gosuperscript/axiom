<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Runtime;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Ok;

/** @internal Compiler for the core coercion bridge. */
final readonly class CoerceSourceCompiler
{
    /**
     * The Coerce bridge types verbatim — the boundary is statically opaque
     * by design (coercion is admission policy, not membership).
     *
     * @return Result<CompiledNode, TypeMismatch>
     */
    public static function compile(Coerce $source, SourceCompilation $compilation): Result
    {
        $inner = $compilation->compile($source->source);

        // A static value the literal registry cannot type still has a total
        // evaluation, and Coerce discards its inner type. Every other source
        // must compile because it runs.
        if ($inner->isErr() && $source->source instanceof StaticSource) {
            $inner = Ok(ConstantNode::from($source->source->value));
        }

        return $inner->map(fn(CompiledNode $inner) => AdmissionNode::from(
            $inner,
            $source->type,
            convert: static fn(mixed $value, Runtime $runtime) => $source->type->coerce($value)
                ->inspect(fn(Option $coerced) => $coerced->inspect(function (mixed $coercedValue) use ($value, $runtime) {
                    if ($coercedValue !== $value) {
                        $runtime->annotate('coercion', get_debug_type($value) . ' -> ' . get_debug_type($coercedValue));
                    }
                })),
            missing: 'The coerced value reads as missing, but %s is required; coerce to %s instead if absence is legal here.',
            label: TypeDescriber::describe($source->type),
        ));
    }
}
