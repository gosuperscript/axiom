<?php

namespace Superscript\Abacus\Types;

use Brick\Math\BigNumber;
use Illuminate\Support\Str;
use NumberFormatter;
use Superscript\Abacus\Exceptions\TransformValueException;
use Superscript\Monads\Option\Some;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Ok;
use Superscript\Monads\Result\Result;

use Webmozart\Assert\Assert;

/**
 * @implements Type<string>
 */
class NumberType implements Type
{
    public function transform(mixed $value): Result
    {
        return (match (true) {
            is_int($value), is_float($value) => new Ok($value),
            $value instanceof BigNumber => new Ok($value->toFloat()),
            is_string($value) && Str::isMatch('/\d+(\.\d+)?\s*%/i', $value) => (function () use ($value) {
                Assert::numeric($value = Str::before($value, '%'));
                return new Ok($value / 100);
            })(),
            !is_numeric($value) => new Err(new TransformValueException(type: 'numeric', value: $value)),
            default => new Ok($value + 0),
        })->map(fn(int|float $value) => new Some($value));
    }

    /**
     * @inheritDoc
     */
    public function compare(mixed $a, mixed $b): bool
    {
        return $a === $b;
    }

    public function format(mixed $value): string
    {
        $formatter = new NumberFormatter('en_GB', NumberFormatter::DECIMAL);
        return $formatter->format($value) ?: (string)$value;

    }
}
