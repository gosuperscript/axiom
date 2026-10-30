<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use InvalidArgumentException;
use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Axiom\Types\Shapes\IdentityLiteralShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Ok;
use Superscript\Monads\Result\Result;
use Throwable;

use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;

/**
 * A singleton of an object-valued base whose values carry identity
 * ({@see HasValueIdentity}): one band, one amount, one date. A value is
 * admitted when its base admits it and its identity key equals the
 * literal's, and is delivered as the literal itself, as {@see LiteralType}
 * delivers scalars.
 *
 * @implements Type<object>
 */
final readonly class IdentityLiteralType implements Type
{
    public function __construct(
        public object $value,
        public Type&HasValueIdentity $base,
    ) {
        if ($base->assert($value)->isErr()) {
            throw new InvalidArgumentException(sprintf(
                'An identity literal must be a value of its base %s; got %s.',
                TypeDescriber::describe($base),
                TransformValueException::format($value),
            ));
        }
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
     * @param Option<mixed> $option
     * @return Result<Option<object>, Throwable>
     */
    private function refine(Option $option): Result
    {
        return $option->mapOr(
            default: new Ok(None()),
            f: fn(mixed $value) => $this->base->identityKey($value) === $this->base->identityKey($this->value)
                ? new Ok(Some($this->value))
                : new Err(new TransformValueException(type: TypeDescriber::describe($this), value: $value)),
        );
    }

    public function format(mixed $value): string
    {
        return $this->base->format($value);
    }

    public function shape(): Shape
    {
        return new IdentityLiteralShape($this->value, $this->base);
    }
}
