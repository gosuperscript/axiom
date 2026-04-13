<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Superscript\Monads\Option\Option;
use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Ok;
use Superscript\Monads\Result\Result;

use function Psl\Vec\map;
use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * @extends AbstractType<array<array-key, mixed>>
 */
class DictType extends AbstractType
{
    public function __construct(
        public Type $type,
    ) {}

    public function accepts(Type $other): bool
    {
        return $other instanceof self && $this->type->accepts($other->type);
    }

    public function name(): string
    {
        return 'Dict<' . $this->type->name() . '>';
    }

    /**
     * @return Option<Type>
     */
    public function memberType(string $name): Option
    {
        return Some($this->type);
    }

    public function assert(mixed $value): Result
    {
        if (! is_array($value)) {
            return new Err(new TransformValueException(
                type: 'dict',
                value: $value,
            ));
        }

        if (empty($value)) {
            return new Ok(None());
        }

        return Result::collect(map($value, function (mixed $item) {
            return $this->type->assert($item)->andThen(fn(Option $value) => $value->mapOr(
                default: Err(new InvalidArgumentException('Dict item can not be a None')),
                f: fn(mixed $value) => Ok($value),
            ));
        }))->map(fn(array $items) => Some(array_combine(array_keys($value), $items)));
    }

    public function coerce(mixed $value): Result
    {
        if (is_string($value) && json_validate($value) && $decoded = \Psl\Json\decode($value)) {
            $value = $decoded;
        }

        if (! is_array($value)) {
            return new Err(new TransformValueException(
                type: 'dict',
                value: $value,
            ));
        }

        if (empty($value)) {
            return new Ok(None());
        }

        return Result::collect(map($value, function (mixed $item) {
            return $this->type->coerce($item)->andThen(fn(Option $value) => $value->mapOr(
                default: Err(new InvalidArgumentException('Dict item can not be a None')),
                f: fn(mixed $value) => Ok($value),
            ));
        }))->map(fn(array $items) => Some(array_combine(array_keys($value), $items)));
    }

    public function compare(mixed $a, mixed $b): bool
    {
        return array_keys($a) === array_keys($b) && array_all(
            array_keys($a),
            fn(int|string $key) => $this->type->compare($a[$key], $b[$key])
        );
    }

    public function format(mixed $value): string
    {
        /** @var array<string> $parts */
        $parts = Arr::map($value, fn(mixed $item, string|int $key) => sprintf("%s: %s", $key, $this->type->format($item)));

        return implode(', ', $parts);
    }
}
