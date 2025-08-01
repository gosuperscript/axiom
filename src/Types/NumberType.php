<?php

declare(strict_types=1);

namespace Superscript\Schema\Types;

use Superscript\Schema\Exceptions\AssertException;
use Superscript\Schema\Exceptions\TransformValueException;
use Superscript\Monads\Option\Some;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Result;

use function Psl\Str\before;
use function Psl\Type\float;
use function Psl\Type\int;
use function Psl\Type\num;
use function Psl\Type\numeric_string;
use function Psl\Type\union;
use function Superscript\Monads\Option\None;
use function Superscript\Monads\Result\Ok;

/**
 * @implements Type<int|float>
 */
class NumberType implements Type
{
    public function coerce(mixed $value): Result
    {
        return (match (true) {
            numeric_string()->matches($value) || num()->matches($value) => Ok(num()->coerce($value)),
            is_string($value) && numeric_string()->matches(before($value, '%')) => Ok(num()->coerce(before($value, '%')) / 100),
            default => new Err(new TransformValueException(type: 'number', value: $value)),
        })->map(fn(int|float $value) => new Some($value));
    }

    public function assert(mixed $value): Result
    {
        return match (true) {
            is_null($value) => Ok(None()),
            union(int(), float())->matches($value) => Ok(new Some($value)),
            default => new Err(new AssertException(type: 'number', value: $value)),
        };
    }

    public function compare(mixed $a, mixed $b): bool
    {
        return $a === $b;
    }
}
