<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

/**
 * @template-covariant T = mixed
 */
interface Type extends Shaped
{
    /**
     * Assert that a value is of type T and return it wrapped in Option
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
     * @return string
     */
    public function format(mixed $value): string;
}
