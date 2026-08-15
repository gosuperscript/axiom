<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Closure;
use RuntimeException;
use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\SourceEvaluation;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/** @internal Shared runtime construction for the two admission bridges. */
final readonly class AdmissionNode
{
    /**
     * Evaluate the inner node, convert present values through the bridge,
     * guard absence, and normalize the admitted value.
     *
     * A bridge claims $type over the value the inner node produces, and a node
     * that did not compile produces none — so the bridge inherits the failure
     * instead of presenting the claimed type over it. Ascription never reaches
     * here with one, because its overlap judgment absorbs first; coercion
     * makes no type judgment at all, by design, so this is its only door.
     *
     * @param Closure(mixed, SourceEvaluation): Result<Option<mixed>, \Throwable> $convert
     */
    public static function from(CompiledSource $inner, Type $type, Closure $convert, string $missing, string $label): CompiledSource
    {
        if ($inner->failed()) {
            return CompiledSource::absorbed();
        }

        $optional = $type->shape() instanceof OptionShape;
        $missing = sprintf($missing, TypeDescriber::describe($type), TypeDescriber::describe(new OptionType($type)));

        return CompiledSource::custom($type, static function (SourceEvaluation $evaluation) use ($inner, $convert, $optional, $missing, $label) {
            try {
                $value = $evaluation->value($inner);

                if ($value === null) {
                    return $optional ? null : Err(new RuntimeException($missing));
                }

                return $convert($value, $evaluation)
                    ->andThen(fn(Option $converted) => $converted->isNone() && !$optional
                        ? Err(new RuntimeException($missing))
                        : Ok($converted->unwrapOr(null)));
            } finally {
                $evaluation->annotate('label', $label);
            }
        });
    }
}
