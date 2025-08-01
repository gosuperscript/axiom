<?php

declare(strict_types=1);

namespace Superscript\Schema\Types;

use InvalidArgumentException;
use Superscript\Monads\Option\Option;
use Superscript\Schema\Exceptions\AssertException;
use Superscript\Schema\Exceptions\TransformValueException;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Result;

use function Psl\Json\decode;
use function Psl\Type\mixed_dict;
use function Psl\Type\mixed_vec;
use function Psl\Type\nullable;
use function Psl\Vec\map;

/**
 * @implements Type<List<mixed>>
 */
class ListType implements Type
{
    public function __construct(
        public Type $type,
    ) {}

    public function coerce(mixed $value): Result
    {
        $value = match (true) {
            is_string($value) && json_validate($value) => decode($value),
            default => $value,
        };

        return match (nullable(mixed_dict())->matches($value)) {
            true => Option::from($value)->map(
                fn($value) => Result::collect(
                    map($value, fn($value) => $this->type->coerce($value)
                    ->transpose()
                    ->okOr(new InvalidArgumentException('List item can not be [None]'))
                    ->andThen(fn($value) => $value)),
                ),
            )->transpose(),
            false => new Err(new TransformValueException(
                type: 'list',
                value: $value,
            )),
        };
    }

    public function assert(mixed $value): Result
    {
        return match (nullable(mixed_vec())->matches($value)) {
            true => Option::from($value)->map(
                fn($value) => Result::collect(
                    map($value, fn($value) => $this->type->assert($value)
                    ->transpose()
                    ->okOr(new InvalidArgumentException('List item can not be [None]'))
                    ->andThen(fn($value) => $value)),
                ),
            )->transpose(),
            false => new Err(new AssertException(type: 'list', value: $value)),
        };
    }

    public function compare(mixed $a, mixed $b): bool
    {
        return count($a) === count($b) && array_all(
            array_keys($a),
            fn(int|string $key) => $this->type->compare($a[$key], $b[$key]),
        );
    }
}
