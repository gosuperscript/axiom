<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use InvalidArgumentException;
use Superscript\Monads\Option\Option;
use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Result;

use function Psl\Vec\map;
use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * @template T = mixed
 * @implements Type<list<T>>
 */
class ListType implements Type
{
    /**
     * @param Type<T> $type
     */
    public function __construct(
        public Type $type,
        public ?int $min = null,
        public ?int $max = null,
    ) {}

    public function assert(mixed $value): Result
    {
        // Strict membership: an associative array is not a list, and
        // asserting never converts — reindexing belongs to coerce.
        if (!is_array($value) || !array_is_list($value)) {
            return new Err(new TransformValueException(
                type: 'list',
                value: $value,
            ));
        }

        if ($outOfBounds = $this->checkBounds($value)) {
            return new Err($outOfBounds);
        }

        return Result::collect(map($value, function (mixed $item) {
            return $this->type->assert($item)->andThen(fn(Option $value) => $value->mapOr(
                default: Err(new InvalidArgumentException('List item can not be a None')),
                f: fn(mixed $value) => Ok($value),
            ));
        }))->map(fn(array $items) => Some($items));
    }

    public function coerce(mixed $value): Result
    {
        if (is_string($value) && json_validate($value) && $decoded = \Psl\Json\decode($value)) {
            $value = $decoded;
        }

        if (!is_array($value)) {
            return new Err(new TransformValueException(
                type: 'list',
                value: $value,
            ));
        }

        if ($outOfBounds = $this->checkBounds($value)) {
            return new Err($outOfBounds);
        }

        return Result::collect(map($value, function (mixed $item) {
            return $this->type->coerce($item)->andThen(fn(Option $value) => $value->mapOr(
                default: Err(new InvalidArgumentException('List item can not be a None')),
                f: fn(mixed $value) => Ok($value),
            ));
        }))->map(fn(array $items) => Some($items));
    }

    public function compare(mixed $a, mixed $b): bool
    {
        return count($a) === count($b) && array_all(
            array_keys($a),
            fn(int|string $key) => $this->type->compare($a[$key], $b[$key])
        );
    }

    public function format(mixed $value): string
    {
        return implode(', ', array_map(fn(mixed $item) => $this->type->format($item), $value));
    }

    public function shape(): Shapes\Shape
    {
        return new Shapes\ListShape($this->type->shape(), $this->min ?? 0, $this->max);
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function checkBounds(array $value): ?InvalidArgumentException
    {
        $count = count($value);

        if ($this->min !== null && $count < $this->min) {
            return new InvalidArgumentException(sprintf('List has %d items but requires at least %d.', $count, $this->min));
        }

        if ($this->max !== null && $count > $this->max) {
            return new InvalidArgumentException(sprintf('List has %d items but allows at most %d.', $count, $this->max));
        }

        return null;
    }
}
