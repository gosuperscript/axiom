<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Ok;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\None;

/**
 * The type of the single null value.
 *
 * Used as the inferred type of a {@see \Superscript\Axiom\Sources\StaticSource}
 * containing null, and as the result type of null-propagating operators.
 *
 * @extends AbstractType<null>
 */
final class NullType extends AbstractType
{
    public function assert(mixed $value): Result
    {
        if ($value !== null) {
            return new Err(new TransformValueException(type: 'null', value: $value));
        }

        return new Ok(None());
    }

    public function coerce(mixed $value): Result
    {
        if ($value === null) {
            return new Ok(None());
        }

        return new Err(new TransformValueException(type: 'null', value: $value));
    }

    public function compare(mixed $a, mixed $b): bool
    {
        return $a === $b;
    }

    public function format(mixed $value): string
    {
        return 'null';
    }

    public function name(): string
    {
        return 'Null';
    }
}
