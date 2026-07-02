<?php

declare(strict_types=1);

namespace Superscript\Axiom\Serialization;

use Superscript\Axiom\Types\Type;
use Superscript\Monads\Option\Option;

/**
 * The bidirectional currency object that crosses the wire: a resolved Type
 * plus an optional value. The type survives even when the value is absent.
 *
 * @template T
 */
final readonly class TypedValue
{
    /**
     * @param Type<T> $type
     * @param Option<T> $value
     */
    public function __construct(
        public Type $type,
        public Option $value,
    ) {}
}
