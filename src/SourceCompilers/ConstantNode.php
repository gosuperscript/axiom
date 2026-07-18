<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\SourceEvaluation;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\UnknownType;

/** @internal Core source compiler implementation. */
final readonly class ConstantNode
{
    /** A static value's evaluation: the value itself, absence for null. */
    public static function from(mixed $value, ?Type $type = null): CompiledSource
    {
        return CompiledSource::custom($type ?? new UnknownType(), static function (SourceEvaluation $evaluation) use ($value) {
            $evaluation->annotate('label', 'static(' . get_debug_type($value) . ')');

            return $value;
        });
    }
}
