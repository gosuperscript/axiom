<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Superscript\Monads\Option\Option;
use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Monads\Result\Result;

use function Psl\Vec\map;
use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * @template T = mixed
 * @implements Type<array<string, T>>
 */
class DictType implements Type
{
    /**
     * @param Type<T> $type
     */
    public function __construct(
        public Type $type,
    ) {}

    /**
     * A dict is a string-keyed map (`Type<array<string, T>>`), so result keys are coerced to string.
     * PHP normalises numeric-string keys back to int, so this has no observable runtime effect — it
     * only realises the declared key type — which is why its mutators are equivalent and ignored.
     *
     * @param array<array-key, mixed> $value
     * @return list<string>
     * @infection-ignore-all
     */
    private function stringKeys(array $value): array
    {
        return array_map(static fn(int|string $key): string => (string) $key, array_keys($value));
    }

    public function assert(mixed $value): Result
    {
        // Strict membership: a non-empty list is not a string-keyed map.
        // The empty array inhabits both List and Dict — PHP has one value
        // where the algebra has two types.
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            return Err(new TransformValueException(
                type: 'dict',
                value: $value,
            ));
        }

        return Result::collect(map($value, function (mixed $item) {
            return $this->type->assert($item)->andThen(fn(Option $value) => $value->mapOr(
                default: Err(new InvalidArgumentException('Dict item can not be a None')),
                f: fn(mixed $value) => Ok($value),
            ));
        }))->map(fn(array $items) => Some(array_combine($this->stringKeys($value), $items)));
    }

    public function coerce(mixed $value): Result
    {
        if (is_string($value) && json_validate($value) && $decoded = \Psl\Json\decode($value)) {
            $value = $decoded;
        }

        // A non-empty list is not a representation of a dict — there is no
        // conversion to perform, so coerce rejects it exactly as assert
        // does (coerce output must inhabit the type). The empty array IS a
        // dict ([] inhabits both List and Dict): a caller who bound [] gets
        // an empty map, not an absence reading — "empty reads as missing"
        // is spelled Option<Dict<T>> by the host that wants it.
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            return Err(new TransformValueException(
                type: 'dict',
                value: $value,
            ));
        }

        return Result::collect(map($value, function (mixed $item) {
            return $this->type->coerce($item)->andThen(fn(Option $value) => $value->mapOr(
                default: Err(new InvalidArgumentException('Dict item can not be a None')),
                f: fn(mixed $value) => Ok($value),
            ));
        }))->map(fn(array $items) => Some(array_combine($this->stringKeys($value), $items)));
    }

    public function format(mixed $value): string
    {
        /** @var array<string> $parts */
        $parts = Arr::map($value, fn(mixed $item, string|int $key) => sprintf("%s: %s", $key, $this->type->format($item)));

        return implode(', ', $parts);
    }

    public function shape(): Shapes\Shape
    {
        return new Shapes\DictShape($this->type->shape());
    }
}
