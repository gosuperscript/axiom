<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

/**
 * @template T = mixed
 */
interface Type
{
    /**
     * Assert that a value is of type T and return it wrapped in Option
     * @param T $value
     * @return Result<Option<T>, Throwable>
     */
    public function assert(mixed $value): Result;

    /**
     * Try to coerce a mixed value into type T
     * @param mixed $value
     * @return Result<Option<T>, Throwable>
     */
    public function coerce(mixed $value): Result;

    /**
     * @param T $a
     * @param T $b
     * @return bool
     */
    public function compare(mixed $a, mixed $b): bool;

    /**
     * @param T $value
     * @return string
     */
    public function format(mixed $value): string;

    /**
     * The canonical lowercase identity of this type in the serialization DSL.
     */
    public static function tag(): string;

    /**
     * The logical arguments of this type: nested Types and/or delimiter-safe
     * scalars (int|float|bool). Together with tag() this fully describes the type.
     *
     * @return list<Type|int|float|bool>
     */
    public function toArgs(): array;

    /**
     * Lossless wire encoding of a present value, distinct from the lossy format().
     *
     * @param T $value
     */
    public function encode(mixed $value): mixed;

    /**
     * Decode a wire value produced by encode().
     *
     * @return Result<T, Throwable>
     */
    public function decode(mixed $value): Result;
}
