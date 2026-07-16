<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Runtime;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\UnknownType;

use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

/** @internal Core source compiler implementation. */
final readonly class ConstantNode
{
    /** A static value's evaluation: the value itself, absence for null. */
    public static function from(mixed $value, ?Type $type = null): CompiledNode
    {
        return new CompiledNode($type ?? new UnknownType(), static function (Runtime $runtime) use ($value) {
            $runtime->annotate('label', 'static(' . get_debug_type($value) . ')');

            return Ok(is_null($value) ? None() : Some($value));
        });
    }
}
