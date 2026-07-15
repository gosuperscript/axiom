<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Ok;
use Superscript\Monads\Result\Result;
use Throwable;

/**
 * A singleton of a scalar base. Numeric identity is loose (5 inhabits
 * LiteralType(5.0)); boolean and string identity is strict.
 *
 * @implements Type<bool|int|float|string>
 */
final readonly class LiteralType implements Type
{
    private Type $base;

    public function __construct(
        public bool|int|float|string $value,
    ) {
        $this->base = match (true) {
            is_bool($value) => new BooleanType(),
            is_string($value) => new StringType(),
            default => new NumberType(),
        };
    }

    public function assert(mixed $value): Result
    {
        return $this->base->assert($value)->andThen(fn(Option $option) => $this->refine($option));
    }

    public function coerce(mixed $value): Result
    {
        return $this->base->coerce($value)->andThen(fn(Option $option) => $this->refine($option));
    }

    /**
     * @param Option<bool|int|float|string> $option
     * @return Result<Option<bool|int|float|string>, Throwable>
     */
    private function refine(Option $option): Result
    {
        return $option->mapOr(
            default: new Ok($option),
            f: fn(mixed $value) => $this->matches($value)
                ? new Ok($option)
                : new Err(new TransformValueException(type: TypeDescriber::describe($this), value: $value)),
        );
    }

    private function matches(mixed $value): bool
    {
        if (is_bool($this->value) || is_string($this->value)) {
            return $value === $this->value;
        }

        return $value == $this->value;
    }

    public function format(mixed $value): string
    {
        return $this->base->format($value);
    }

    public function shape(): Shape
    {
        return new LiteralShape($this->value);
    }
}
