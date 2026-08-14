<?php

declare(strict_types=1);

namespace Superscript\Axiom\Spike;

use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;

/** SPIKE ONLY. Rendering that tolerates ErrorType, which TypeDescriber would render as `Never`. */
final class SpikeTypes
{
    public const string ErrorLabel = '<error>';

    public static function describe(Type $type): string
    {
        return $type instanceof ErrorType ? self::ErrorLabel : TypeDescriber::describe($type);
    }
}
