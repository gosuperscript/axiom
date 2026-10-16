<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use NumberFormatter;
use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Axiom\Types\Shapes\NumberShape;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Result;

use function Psl\Type\string;
use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

/**
 * Whole numbers: a count of employees, storeys, or premises. An Integer is
 * a Number, so its values reach every Number slot and arithmetic over them
 * yields a Number; a Number never reaches an Integer slot, because it may
 * be fractional.
 *
 * @implements Type<int>
 */
class IntegerType implements Type
{
    public function assert(mixed $value): Result
    {
        if (!is_int($value)) {
            return new Err(new TransformValueException(type: 'integer', value: $value));
        }

        return Ok(Some($value));
    }

    /**
     * Only an integer spelling converts: an int, or a string such as '3',
     * '-3' or '+3' that names one within the int range. Every float and
     * every decimal or exponent spelling is refused, even when its value is
     * whole — 3.0, '3.0' and '1e3' are written as decimals, not integers.
     */
    public function coerce(mixed $value): Result
    {
        if (is_string($value) && ($value === '' || $value === 'null')) {
            return Ok(None());
        }

        $integer = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

        return $integer === false
            ? new Err(new TransformValueException(type: 'integer', value: $value))
            : Ok(Some($integer));
    }

    /**
     * @param int $value
     */
    public function format(mixed $value): string
    {
        $formatter = new NumberFormatter('en_GB', NumberFormatter::DECIMAL);

        return string()->assert($formatter->format($value));
    }

    public function shape(): Shapes\Shape
    {
        return new NumberShape(integer: true);
    }
}
