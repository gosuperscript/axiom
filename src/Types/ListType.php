<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use InvalidArgumentException;
use Superscript\Axiom\Exceptions\RecordPropertyViolation;
use Superscript\Monads\Option\Option;
use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Monads\Result\Result;
use Throwable;

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
    ) {
        // Bounds are claims other judgments trust (listOverlapsDict tests
        // min === 0), so an impossible claim is a construction error, not
        // a value-set of surprising emptiness.
        if ($this->min !== null && $this->min < 0) {
            throw new InvalidArgumentException(sprintf('List bounds must be sensible: min %d is negative.', $this->min));
        }

        if ($this->max !== null && $this->max < ($this->min ?? 0)) {
            throw new InvalidArgumentException(sprintf('List bounds must be sensible: max %d is below min %d.', $this->max, $this->min ?? 0));
        }
    }

    public function assert(mixed $value): Result
    {
        // Strict membership: an associative array is not a list, and
        // asserting never converts — reindexing belongs to coerce.
        if (!is_array($value) || !array_is_list($value)) {
            return Err(new TransformValueException(
                type: 'list',
                value: $value,
            ));
        }

        return $this->checkBounds($value)
            ->andThen(fn(array $items) => $this->transform($items, $this->type->assert(...)));
    }

    public function coerce(mixed $value): Result
    {
        if (is_string($value) && json_validate($value) && $decoded = \Psl\Json\decode($value)) {
            $value = $decoded;
        }

        if (!is_array($value)) {
            return Err(new TransformValueException(
                type: 'list',
                value: $value,
            ));
        }

        return $this->checkBounds($value)
            ->andThen(fn(array $items) => $this->transform($items, $this->type->coerce(...)));
    }

    /**
     * @param list<mixed> $value
     */
    public function format(mixed $value): string
    {
        return implode(', ', array_map(fn(mixed $item) => $this->type->format($item), $value));
    }

    public function shape(): Shapes\Shape
    {
        return new Shapes\ListShape($this->type->shape(), $this->min ?? 0, $this->max);
    }

    /**
     * @param array<array-key, mixed> $items
     * @param callable(mixed): Result<Option<T>, Throwable> $transform
     * @return Result<Option<list<T>>, Throwable>
     */
    private function transform(array $items, callable $transform): Result
    {
        $items = array_values($items);

        /** @var list<Result<T, Throwable>> $admitted */
        $admitted = array_map(
            static fn(int $index, mixed $item): Result => $transform($item)
                ->mapErr(static fn(Throwable $failure): Throwable => $failure instanceof RecordPropertyViolation
                    ? $failure->asElementFailure($index)
                    : $failure)
                ->andThen(fn(Option $value) => $value->mapOr(
                    default: Err(new InvalidArgumentException('List item can not be a None')),
                    f: fn($value) => Ok($value),
                )),
            array_keys($items),
            $items,
        );

        $result = Result::collect($admitted)->map(fn(array $items) => Some($items));

        /** @var Result<Option<list<T>>, Throwable> $result */
        return $result;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return Result<array<array-key, mixed>, InvalidArgumentException>
     */
    private function checkBounds(array $value): Result
    {
        $count = count($value);

        if ($this->min !== null && $count < $this->min) {
            return Err(new InvalidArgumentException(sprintf('List has %d items but requires at least %d.', $count, $this->min)));
        }

        if ($this->max !== null && $count > $this->max) {
            return Err(new InvalidArgumentException(sprintf('List has %d items but allows at most %d.', $count, $this->max)));
        }

        return Ok($value);
    }
}
