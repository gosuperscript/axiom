<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Monads\Option\None;
use Superscript\Monads\Option\Option;

/**
 * Base implementation of the non-coercion parts of {@see Type}.
 *
 * Provides sensible defaults for the type-relationship methods introduced
 * alongside {@see \Superscript\Axiom\TypeChecker}. Concrete types still
 * implement the coercion/assert/compare/format methods themselves.
 *
 * @template T
 * @implements Type<T>
 */
abstract class AbstractType implements Type
{
    public function accepts(Type $other): bool
    {
        return $other::class === static::class;
    }

    public function name(): string
    {
        $parts = explode('\\', static::class);
        $short = end($parts);

        return preg_replace('/Type$/', '', $short) ?: $short;
    }

    public function memberType(string $name): Option
    {
        return new None();
    }
}
