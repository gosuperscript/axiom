<?php

declare(strict_types=1);

namespace Superscript\Abacus\Types;

use NumberFormatter;
use Superscript\Abacus\Exceptions\TransformValueException;
use Superscript\Monads\Option\Some;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Result;

use function Psl\Str\before;
use function Psl\Type\num;
use function Psl\Type\numeric_string;
use function Psl\Type\string;
use function Superscript\Monads\Result\Ok;

/**
 * @implements Type<int|float>
 */
class NumberType implements Type
{
    public function transform(mixed $value): Result
    {
        return (match (true) {
            numeric_string()->matches($value) || num()->matches($value) => Ok(num()->coerce($value)),
            is_string($value) && numeric_string()->matches(before($value, '%')) => Ok(num()->coerce(before($value, '%')) / 100),
            default => new Err(new TransformValueException(type: 'numeric', value: $value)),
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

        return string()->assert($formatter->format($value));
    }
}
