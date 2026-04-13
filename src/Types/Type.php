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
     * Whether a value of $other can be used where this type is expected.
     *
     * Default: exact class match. Override to allow subtype compatibility.
     */
    public function accepts(Type $other): bool;

    /**
     * Human-readable name used in type-check error messages.
     */
    public function name(): string;

    /**
     * The declared type of a named member of a value of this type, if any.
     *
     * Returns None for scalar types. DictType/ListType override to describe
     * their member types so MemberAccessSource can propagate static typing.
     *
     * @return Option<Type>
     */
    public function memberType(string $name): Option;
}
