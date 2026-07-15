<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

/**
 * A possibly-absent value: {null} ∪ inner.
 *
 * Coercion law: null coerces to a *present* Some(null) — absence is a legal
 * value of the option, not a failed coercion. That is what lets an optional
 * field live inside a record whose fields treat a None coercion as
 * "required but missing". An inner absence reading ('' for strings, etc.)
 * coerces to Some(null) for the same reason.
 *
 * @implements Type<mixed>
 */
final readonly class OptionType implements Type
{
    public function __construct(
        public Type $inner,
    ) {}

    public function assert(mixed $value): Result
    {
        if ($value === null) {
            return Ok(Some(null));
        }

        return $this->inner->assert($value);
    }

    public function coerce(mixed $value): Result
    {
        if ($value === null) {
            return Ok(Some(null));
        }

        return $this->inner->coerce($value)
            ->map(fn(Option $option) => $option->isNone() ? Some(null) : $option);
    }

    public function format(mixed $value): string
    {
        return $value === null ? '' : $this->inner->format($value);
    }

    public function shape(): Shape
    {
        return new OptionShape($this->inner->shape());
    }
}
