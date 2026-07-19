<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\SourceEvaluation;
use Superscript\Axiom\Sources\DefaultValue;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeReifier;

/** @internal Compiler for the core explicit-default source. */
final readonly class DefaultValueSourceCompiler
{
    public static function compile(DefaultValue $source, SourceCompilation $compilation): CompiledSource
    {
        $inner = $compilation->child($source->source, 'source');

        $shape = $inner->returns->shape();

        if (!$shape instanceof OptionShape) {
            return $inner;
        }

        $present = $inner->returns instanceof OptionType
            ? $inner->returns->inner
            : TypeReifier::reify($shape->inner);
        $coerced = $present->coerce($source->default);

        if ($coerced->isErr()) {
            $compilation->reject(new TypeMismatch(sprintf(
                'The default value cannot be coerced to %s: %s',
                TypeDescriber::describe($present),
                $coerced->unwrapErr()->getMessage(),
            )));
        }

        $default = $coerced->unwrap();

        if ($default->isNone()) {
            $compilation->reject(new TypeMismatch(sprintf(
                'The default value reads as missing, but a present %s is required.',
                TypeDescriber::describe($present),
            )));
        }

        $value = $default->unwrap();

        return $compilation->custom($present, static function (SourceEvaluation $evaluation) use ($inner, $value) {
            try {
                $sourceValue = $evaluation->value($inner);
                $evaluation->annotate('source', $sourceValue);
                $evaluation->annotate('used_default', $sourceValue === null);

                $result = $sourceValue ?? $value;
                $evaluation->annotate('result', $result);

                return $result;
            } finally {
                $evaluation->annotate('label', 'default');
            }
        });
    }
}
